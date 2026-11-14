<?php

namespace App\Services;

use App\Events\StockActualizado;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Movimiento;
use App\Models\Numerador;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\TomaInventario;
use App\Models\Ubicacion;
use App\Models\User;
use App\Support\EmpresaActual;
use App\Support\Formato;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Motor de stock. Es el ÚNICO lugar del sistema que modifica existencias, lotes y series.
 *
 * Garantías:
 * - Todo o nada: cada movimiento corre en una transacción.
 * - Sin stock fantasma: bloquea (SELECT … FOR UPDATE) los productos involucrados,
 *   así dos operaciones simultáneas sobre el mismo producto se ejecutan una detrás de otra.
 * - Sin duplicados: si llega dos veces la misma referencia_externa, devuelve el movimiento ya registrado.
 * - Kardex con saldo por depósito y costo promedio ponderado actualizado.
 * - Lotes: las entradas exigen lote; las salidas sin lote toman primero lo que vence antes (FEFO)
 *   y no descuentan solos los lotes vencidos.
 * - Series: cada unidad con número de serie se sigue de punta a punta (en qué depósito está y por dónde pasó).
 *
 * Formato de cada línea:
 *   producto_id, cantidad (con signo en registrar/ajustar; positiva en ingresar/egresar),
 *   deposito_id?, ubicacion_id?, costo_unitario?, presentacion_id?, cantidad_presentacion?,
 *   lote_id? | lote_codigo? + lote_vencimiento? (Y-m-d), series? (array o texto, uno por línea)
 */
class StockService
{
    public const TOLERANCIA = 0.00005;

    /* =========================================================
       Operaciones
       ========================================================= */

    public function cargarInicial(int $depositoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        return $this->registrar(Movimiento::TIPO_INICIAL, $depositoId, $this->conSigno($lineas, 1), $opciones, $usuario);
    }

    public function ingresar(int $depositoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        return $this->registrar(Movimiento::TIPO_INGRESO, $depositoId, $this->conSigno($lineas, 1), $opciones, $usuario);
    }

    public function egresar(int $depositoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        return $this->registrar(Movimiento::TIPO_EGRESO, $depositoId, $this->conSigno($lineas, -1), $opciones, $usuario);
    }

    /** Ajuste: cada línea con su signo (+ suma, − resta). */
    public function ajustar(int $depositoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        return $this->registrar(Movimiento::TIPO_AJUSTE, $depositoId, $lineas, $opciones, $usuario);
    }

    /**
     * Ajuste por conteo: cada línea trae "contado" (lo que hay físicamente).
     * La diferencia contra el sistema se calcula con el producto bloqueado.
     * Compara contra la ubicación y/o el lote indicados; sin ninguno, contra el total del depósito.
     * Las líneas sin diferencia no se registran. No aplica a productos con número de serie.
     */
    public function ajustarAContado(int $depositoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        $lineas = array_map(function ($linea) {
            $linea['contado'] = $linea['contado'] ?? null;
            unset($linea['cantidad']);

            return $linea;
        }, array_values($lineas));

        return $this->registrar(Movimiento::TIPO_AJUSTE, $depositoId, $lineas, $opciones, $usuario);
    }

    /**
     * Transferencia directa entre depósitos en un solo movimiento.
     * Lo que entra en destino es exactamente lo que salió del origen (mismos lotes y series).
     * Cada línea admite ubicacion_id (origen), ubicacion_destino_id, lote_id y series.
     */
    public function transferir(int $origenId, int $destinoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        if ($origenId === $destinoId) {
            throw ValidationException::withMessages([
                'deposito_destino_id' => 'El depósito de destino tiene que ser distinto al de origen.',
            ]);
        }

        return $this->registrar(
            Movimiento::TIPO_TRANSFERENCIA,
            $origenId,
            $this->renglonesEspejo($lineas, $origenId, $destinoId),
            ['deposito_destino_id' => $destinoId] + $opciones,
            $usuario
        );
    }

    /**
     * Arma pares de renglones: salida del origen + entrada "espejo" en el destino.
     * La usan transferir() y TransferenciaService.
     */
    public function renglonesEspejo(array $lineas, int $origenId, int $destinoId): array
    {
        $renglones = [];

        foreach (array_values($lineas) as $linea) {
            $cantidad = abs((float) ($linea['cantidad'] ?? 0));

            $renglones[] = [
                'producto_id' => $linea['producto_id'] ?? null,
                'deposito_id' => $origenId,
                'ubicacion_id' => $linea['ubicacion_id'] ?? null,
                'lote_id' => $linea['lote_id'] ?? null,
                'series' => $linea['series'] ?? null,
                'cantidad' => -$cantidad,
            ];

            $renglones[] = [
                'producto_id' => $linea['producto_id'] ?? null,
                'deposito_id' => $destinoId,
                'ubicacion_id' => $linea['ubicacion_destino_id'] ?? null,
                'cantidad' => $cantidad,
                'espejo_de' => count($renglones) - 1,
            ];
        }

        return $renglones;
    }

    /**
     * Anula un movimiento generando el contramovimiento (nunca se borra nada).
     * Si el stock que entró ya se consumió y el depósito no permite negativo, no deja anular.
     */
    public function anular(Movimiento $movimiento, ?User $usuario = null, ?string $motivo = null, array $opciones = []): Movimiento
    {
        return DB::transaction(function () use ($movimiento, $usuario, $motivo, $opciones) {
            $original = Movimiento::query()->whereKey($movimiento->id)->lockForUpdate()->firstOrFail();
            $interno = (bool) ($opciones['interno_transferencia'] ?? false);

            if (! $original->puedeAnularse()) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este movimiento ya está anulado o no se puede anular.',
                ]);
            }

            if ($original->transferencia_id && ! $interno) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este movimiento es parte de una transferencia: se anula desde Transferencias.',
                ]);
            }

            if ($original->recepcion_compra_id && empty($opciones['interno_compra'])) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este movimiento es una recepción de compra: se anula desde Compras.',
                ]);
            }

            if ($original->remito_id && empty($opciones['interno_remito'])) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este movimiento es un remito de entrega: se anula desde el remito.',
                ]);
            }

            // Primero se revierten las entradas (así las series vuelven a estar libres) y después las salidas
            $lineas = $original->items()->with('series')->get()
                ->sortBy(fn ($item) => (float) $item->cantidad > 0 ? 0 : 1)
                ->map(fn ($item) => [
                    'producto_id' => $item->producto_id,
                    'deposito_id' => $item->deposito_id,
                    'ubicacion_id' => $item->ubicacion_id,
                    'lote_id' => $item->lote_id,
                    'series' => $item->series->isNotEmpty() ? $item->series->pluck('numero')->all() : null,
                    'cantidad' => -(float) $item->cantidad,
                    'costo_unitario' => (float) $item->costo_unitario,
                    'exacta' => true,
                ])->values()->all();

            $motivo = filled($motivo) ? trim($motivo) : null;

            $contra = $this->registrar(Movimiento::TIPO_ANULACION, $original->deposito_id, $lineas, [
                'deposito_destino_id' => $original->deposito_destino_id,
                'anula_a_id' => $original->id,
                'origen' => $usuario ? 'manual' : 'sistema',
                'observaciones' => trim("Anulación del movimiento N° {$original->numero_texto}. " . ($motivo ?? '')),
                'revertir_costo' => $original->tipo !== Movimiento::TIPO_TRANSFERENCIA,
                'transferencia_id' => $original->transferencia_id,
                'recepcion_compra_id' => $original->recepcion_compra_id,
                'remito_id' => $original->remito_id,
                'interno_transferencia' => $interno,
            ], $usuario);

            $original->update([
                'estado' => Movimiento::ESTADO_ANULADO,
                'anulado_por_id' => $contra->id,
                'anulado_at' => now(),
                'anulado_user_id' => $usuario?->id,
                'motivo_anulacion' => $motivo,
            ]);

            return $contra;
        });
    }

    /* =========================================================
       Consultas
       ========================================================= */

    public function cantidad(int $productoId, ?int $depositoId = null): float
    {
        return (float) Existencia::query()
            ->where('producto_id', $productoId)
            ->when($depositoId, fn ($q) => $q->where('deposito_id', $depositoId))
            ->sum('cantidad');
    }

    /**
     * Stock de varios productos en un depósito: total, reservado, disponible, por ubicación (0 = sin ubicar) y por lote.
     * [producto_id => [
     *     'total' => 12.0, 'reservado' => 2.0, 'disponible' => 10.0,
     *     'ubicaciones' => [0 => 2.0, 15 => 10.0],
     *     'lotes' => [8 => ['id' => 8, 'codigo' => 'L-24', 'vencimiento' => '2026-12-01', 'vencido' => false, 'cantidad' => 12.0]],
     * ]]
     */
    public function resumen(array $productoIds, int $depositoId): array
    {
        if (! $productoIds) {
            return [];
        }

        $filas = Existencia::query()
            ->whereIn('producto_id', $productoIds)
            ->where('deposito_id', $depositoId)
            ->selectRaw('producto_id, ubicacion_id, lote_id, SUM(cantidad) AS total, SUM(reservado) AS reservado')
            ->groupBy('producto_id', 'ubicacion_id', 'lote_id')
            ->get();

        $lotes = Lote::whereIn('id', $filas->pluck('lote_id')->filter()->unique())->get()->keyBy('id');
        $resultado = [];

        foreach ($filas as $fila) {
            $id = $fila->producto_id;
            $total = (float) $fila->total;
            $ubicacion = (int) ($fila->ubicacion_id ?? 0);

            $resultado[$id]['total'] = round(($resultado[$id]['total'] ?? 0) + $total, 4);
            $resultado[$id]['reservado'] = round(($resultado[$id]['reservado'] ?? 0) + (float) $fila->reservado, 4);
            $resultado[$id]['disponible'] = round($resultado[$id]['total'] - $resultado[$id]['reservado'], 4);
            $resultado[$id]['ubicaciones'][$ubicacion] = round(($resultado[$id]['ubicaciones'][$ubicacion] ?? 0) + $total, 4);
            $resultado[$id]['lotes'] ??= [];

            if ($fila->lote_id && ($lote = $lotes->get($fila->lote_id))) {
                $actual = $resultado[$id]['lotes'][$lote->id]['cantidad'] ?? 0;
                $resultado[$id]['lotes'][$lote->id] = [
                    'id' => $lote->id,
                    'codigo' => $lote->codigo,
                    'vencimiento' => $lote->fecha_vencimiento?->toDateString(),
                    'vencido' => $lote->estaVencido(),
                    'cantidad' => round($actual + $total, 4),
                ];
            }
        }

        // Los lotes, ordenados por vencimiento y sin los que quedaron en cero
        foreach ($resultado as $id => $datos) {
            $resultado[$id]['lotes'] = collect($datos['lotes'])
                ->filter(fn ($l) => abs($l['cantidad']) > self::TOLERANCIA)
                ->sortBy(fn ($l) => [$l['vencimiento'] === null ? 1 : 0, $l['vencimiento'] ?? '', $l['codigo']])
                ->values()
                ->all();
        }

        return $resultado;
    }

    /** Stock que se puede usar: real menos reservado (opcionalmente de un lote). */
    public function disponible(int $productoId, int $depositoId, ?int $loteId = null): float
    {
        $fila = Existencia::query()
            ->where('producto_id', $productoId)
            ->where('deposito_id', $depositoId)
            ->when($loteId, fn ($q) => $q->where('lote_id', $loteId))
            ->selectRaw('COALESCE(SUM(cantidad), 0) AS cantidad, COALESCE(SUM(reservado), 0) AS reservado')
            ->first();

        return round((float) $fila->cantidad - (float) $fila->reservado, 4);
    }

    public function porReferencia(string $referencia): ?Movimiento
    {
        return Movimiento::with('items')->where('referencia_externa', $referencia)->first();
    }

    /* =========================================================
       Núcleo
       ========================================================= */

    /**
     * Opciones: origen, motivo_id, referencia_externa, comprobante, fecha_comprobante, observaciones,
     *           deposito_destino_id, anula_a_id, transferencia_id, recepcion_compra_id, remito_id,
     *           revertir_costo / interno_transferencia (uso interno de anular y de TransferenciaService)
     */
    public function registrar(string $tipo, int $depositoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        $empresa = EmpresaActual::modelo();

        if (! $empresa) {
            throw new RuntimeException('No hay una empresa seleccionada para registrar el movimiento.');
        }

        $referencia = filled($opciones['referencia_externa'] ?? null) ? (string) $opciones['referencia_externa'] : null;

        if ($referencia && ($existente = $this->porReferencia($referencia))) {
            return $existente;
        }

        $esAnulacion = $tipo === Movimiento::TIPO_ANULACION;
        $interno = (bool) ($opciones['interno_transferencia'] ?? false);
        $lineas = $this->normalizar($lineas, $depositoId, $empresa, $usuario, $esAnulacion, $interno);

        if (empty($opciones['interno_inventario'])) {
            $this->controlarCongelados(collect($lineas)->pluck('deposito_id')->push($depositoId)->unique()->all());
        }

        try {
            return DB::transaction(fn () => $this->aplicar($tipo, $depositoId, $lineas, $opciones, $usuario, $referencia));
        } catch (UniqueConstraintViolationException $e) {
            // Dos pedidos simultáneos con la misma referencia: el segundo devuelve el del primero
            if ($referencia && ($existente = $this->porReferencia($referencia))) {
                return $existente;
            }

            throw $e;
        }
    }

    protected function aplicar(string $tipo, int $depositoId, array $lineas, array $opciones, ?User $usuario, ?string $referencia): Movimiento
    {
        $productoIds = collect($lineas)->pluck('producto_id')->unique()->sort()->values()->all();
        $depositoIds = collect($lineas)->pluck('deposito_id')->unique()->values()->all();

        // 1) Bloquear los productos (siempre en el mismo orden para evitar bloqueos cruzados)
        $productos = Producto::withTrashed()
            ->whereIn('id', $productoIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $productos->load('unidad');

        $depositos = Deposito::withTrashed()->with('empresa')->whereIn('id', $depositoIds)->get()->keyBy('id');

        // 2) Saldos actuales por depósito y totales de la empresa (para el costo promedio)
        $saldoDeposito = [];
        $stockEmpresa = [];

        Existencia::query()
            ->whereIn('producto_id', $productoIds)
            ->selectRaw('producto_id, deposito_id, SUM(cantidad) AS total')
            ->groupBy('producto_id', 'deposito_id')
            ->get()
            ->each(function ($fila) use (&$saldoDeposito, &$stockEmpresa) {
                $saldoDeposito["{$fila->producto_id}-{$fila->deposito_id}"] = (float) $fila->total;
                $stockEmpresa[$fila->producto_id] = ($stockEmpresa[$fila->producto_id] ?? 0) + (float) $fila->total;
            });

        $revertirCosto = (bool) ($opciones['revertir_costo'] ?? false);
        $excluirVencidos = in_array($tipo, [Movimiento::TIPO_EGRESO, Movimiento::TIPO_TRANSFERENCIA], true);
        $fecha = now();
        $errores = [];
        $items = [];
        $aplicadas = []; // índice de línea => partes aplicadas (para los renglones espejo)

        // 3) Aplicar cada línea
        foreach ($lineas as $i => $linea) {
            $producto = $productos->get($linea['producto_id']);
            $deposito = $depositos->get($linea['deposito_id']);
            $clave = "{$producto->id}-{$deposito->id}";
            $saldoAntes = $saldoDeposito[$clave] ?? 0.0;
            $aplicadas[$i] = [];

            // Lote nuevo (se crea recién acá, dentro de la transacción)
            if ($linea['lote_nuevo']) {
                $lote = Lote::firstOrCreate(
                    ['producto_id' => $producto->id, 'codigo' => $linea['lote_nuevo']['codigo']],
                    ['fecha_vencimiento' => $linea['lote_nuevo']['vencimiento']]
                );
                $linea['lote_id'] = $lote->id;
            }

            // Ajuste por conteo: la cantidad es la diferencia entre lo contado y lo que dice el sistema
            if ($linea['contado'] !== null) {
                $enSistema = $this->saldoFiltrado($producto->id, $deposito->id, $linea['ubicacion_id'], $linea['lote_id'], $saldoAntes);
                $linea['cantidad'] = round($linea['contado'] - $enSistema, 4);

                if (abs($linea['cantidad']) < self::TOLERANCIA) {
                    continue;
                }
            }

            // Toda entrada de un producto con lotes tiene que decir de qué lote es
            if ($producto->usa_lotes && $linea['cantidad'] > 0 && ! $linea['lote_id'] && $linea['espejo_de'] === null && ! $linea['exacta']) {
                $errores[] = "{$producto->nombre}: indicá el lote" . ($linea['contado'] !== null ? ' del sobrante.' : '.');
                continue;
            }

            $partes = $this->partes($linea, $producto, $deposito, $excluirVencidos, $aplicadas, $errores);

            if ($partes === null) {
                continue;
            }

            foreach ($partes as $parte) {
                $existencia = Existencia::para($producto->id, $deposito->id, $parte['ubicacion_id'], $parte['lote_id']);
                $actual = (float) $existencia->cantidad;
                $nueva = round($actual + $parte['cantidad'], 4);

                if ($parte['cantidad'] < 0 && $nueva < -self::TOLERANCIA && ! $deposito->permiteStockNegativo()) {
                    $errores[] = $this->mensajeInsuficiente($producto, $deposito, $linea, $parte, $actual, $saldoAntes, $excluirVencidos);
                    continue;
                }

                $serieIds = $parte['series'] !== null
                    ? $this->moverSeries($producto, $deposito, $parte, $errores)
                    : [];

                if ($serieIds === null) {
                    continue;
                }

                $existencia->cantidad = $nueva;
                $existencia->save();

                $saldoDeposito[$clave] = round(($saldoDeposito[$clave] ?? 0) + $parte['cantidad'], 4);
                $stockPrevio = $stockEmpresa[$producto->id] ?? 0.0;
                $stockEmpresa[$producto->id] = round($stockPrevio + $parte['cantidad'], 4);

                // Costo: los ingresos con costo recalculan el promedio; el resto se valoriza al promedio vigente
                $costo = $linea['costo_unitario'];
                $mueveCosto = $costo !== null && (
                    (in_array($tipo, [Movimiento::TIPO_INICIAL, Movimiento::TIPO_INGRESO], true) && $parte['cantidad'] > 0)
                    || ($tipo === Movimiento::TIPO_ANULACION && $revertirCosto)
                );

                if ($mueveCosto) {
                    $this->recalcularPromedio($producto, $stockPrevio, $parte['cantidad'], $costo);
                } else {
                    $costo ??= $this->costoVigente($producto);
                }

                $aplicadas[$i][] = $parte;

                $items[] = [
                    'producto_id' => $producto->id,
                    'deposito_id' => $deposito->id,
                    'ubicacion_id' => $parte['ubicacion_id'],
                    'lote_id' => $parte['lote_id'],
                    'presentacion_id' => $linea['presentacion_id'],
                    'cantidad_presentacion' => $linea['cantidad_presentacion'],
                    'cantidad' => $parte['cantidad'],
                    'cantidad_contada' => $linea['contado'],
                    'saldo' => $saldoDeposito[$clave],
                    'costo_unitario' => round($costo, 4),
                    'fecha' => $fecha,
                    'series' => $serieIds,
                ];
            }
        }

        // Las salidas comunes no pueden tomar stock reservado (las entregas de una reserva lo liberan antes)
        if (! $errores && in_array($tipo, [Movimiento::TIPO_EGRESO, Movimiento::TIPO_TRANSFERENCIA], true)) {
            $errores = $this->controlarReservas($items, $productos, $depositos);
        }

        if ($errores) {
            throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores))]);
        }

        if (! $items) {
            throw ValidationException::withMessages([
                'lineas' => 'Las cantidades contadas coinciden con el stock del sistema: no hay diferencias para ajustar.',
            ]);
        }

        foreach ($productos as $producto) {
            if ($producto->isDirty('costo_promedio')) {
                $producto->save();
            }
        }

        // 4) Cabecera y renglones
        $movimiento = Movimiento::create([
            'numero' => Numerador::siguiente('movimiento'),
            'tipo' => $tipo,
            'motivo_id' => $opciones['motivo_id'] ?? null,
            'estado' => Movimiento::ESTADO_CONFIRMADO,
            'origen' => $opciones['origen'] ?? ($usuario ? 'manual' : 'sistema'),
            'referencia_externa' => $referencia,
            'deposito_id' => $depositoId,
            'deposito_destino_id' => $opciones['deposito_destino_id'] ?? null,
            'comprobante' => $opciones['comprobante'] ?? null,
            'fecha_comprobante' => $opciones['fecha_comprobante'] ?? null,
            'fecha' => $fecha,
            'observaciones' => $opciones['observaciones'] ?? null,
            'user_id' => $usuario?->id,
            'anula_a_id' => $opciones['anula_a_id'] ?? null,
            'transferencia_id' => $opciones['transferencia_id'] ?? null,
            'recepcion_compra_id' => $opciones['recepcion_compra_id'] ?? null,
            'remito_id' => $opciones['remito_id'] ?? null,
            'toma_inventario_id' => $opciones['toma_inventario_id'] ?? null,
        ]);

        foreach ($items as $item) {
            $renglon = $movimiento->items()->create(Arr::except($item, ['series']));

            if ($item['series']) {
                $renglon->series()->attach($item['series']);
            }
        }

        event(new StockActualizado($movimiento->empresa_id, $movimiento->id, $productoIds, $depositoIds));

        return $movimiento->load('items');
    }

    /** Un depósito con una toma de inventario "congelada" en curso no acepta movimientos hasta que se cierre. */
    protected function controlarCongelados(array $depositoIds): void
    {
        $toma = TomaInventario::query()
            ->with('deposito')
            ->where('estado', TomaInventario::EN_CURSO)
            ->where('congela', true)
            ->whereIn('deposito_id', $depositoIds)
            ->first();

        if ($toma) {
            throw ValidationException::withMessages([
                'deposito_id' => "El depósito {$toma->deposito?->nombre} está congelado por la toma de inventario N° {$toma->numero_texto}: "
                    . 'no admite movimientos hasta que se cierre o se cancele.',
            ]);
        }
    }

    protected function controlarReservas(array $items, $productos, $depositos): array
    {
        $pares = collect($items)
            ->filter(fn ($i) => $i['cantidad'] < 0)
            ->map(fn ($i) => [$i['producto_id'], $i['deposito_id']])
            ->unique(fn ($par) => implode('-', $par));

        $errores = [];

        foreach ($pares as [$productoId, $depositoId]) {
            $deposito = $depositos->get($depositoId);

            if (! $deposito || $deposito->es_transito || $deposito->permiteStockNegativo()) {
                continue;
            }

            $fila = Existencia::query()
                ->where('producto_id', $productoId)
                ->where('deposito_id', $depositoId)
                ->selectRaw('COALESCE(SUM(cantidad), 0) AS cantidad, COALESCE(SUM(reservado), 0) AS reservado')
                ->first();

            $reservado = (float) $fila->reservado;
            $disponible = (float) $fila->cantidad - $reservado;

            if ($reservado > self::TOLERANCIA && $disponible < -self::TOLERANCIA) {
                $producto = $productos->get($productoId);
                $errores[] = "{$producto?->nombre}: en {$deposito->nombre} hay " . Formato::cantidad($reservado)
                    . ' ' . ($producto?->unidad?->abreviatura ?? '') . ' reservadas para clientes. Esa mercadería sale con el remito de la reserva (o liberá la reserva primero).';
            }
        }

        return $errores;
    }

    /**
     * En qué filas de existencia impacta una línea.
     * Cada parte: ubicacion_id, lote_id, cantidad, series (números o null).
     * Devuelve null si la línea tiene errores (ya agregados a $errores).
     */
    protected function partes(array $linea, Producto $producto, Deposito $deposito, bool $excluirVencidos, array $aplicadas, array &$errores): ?array
    {
        // Renglón espejo: entra exactamente lo que salió en la línea de origen (mismos lotes y series)
        if ($linea['espejo_de'] !== null) {
            return array_map(fn ($origen) => [
                'ubicacion_id' => $linea['ubicacion_id'],
                'lote_id' => $origen['lote_id'],
                'cantidad' => -$origen['cantidad'],
                'series' => $origen['series'],
            ], $aplicadas[$linea['espejo_de']] ?? []);
        }

        // Productos con número de serie: las series indicadas mandan
        if ($linea['series'] !== null) {
            if ($linea['cantidad'] > 0) {
                return [[
                    'ubicacion_id' => $linea['ubicacion_id'],
                    'lote_id' => $linea['lote_id'],
                    'cantidad' => $linea['cantidad'],
                    'series' => $linea['series'],
                ]];
            }

            return $this->partesPorSeries($linea, $producto, $deposito, $errores);
        }

        $simple = [[
            'ubicacion_id' => $linea['ubicacion_id'],
            'lote_id' => $linea['lote_id'],
            'cantidad' => $linea['cantidad'],
            'series' => null,
        ]];

        $estaDefinida = $linea['ubicacion_id'] && ($linea['lote_id'] || ! $producto->usa_lotes);

        if ($linea['cantidad'] > 0 || $linea['exacta'] || $estaDefinida) {
            return $simple;
        }

        // Salida a repartir: FEFO por lote (si usa lotes) y, dentro de eso, lo "sin ubicar" primero
        $consulta = Existencia::query()
            ->select('existencias.*')
            ->where('existencias.producto_id', $producto->id)
            ->where('existencias.deposito_id', $deposito->id)
            ->where('existencias.cantidad', '>', 0)
            ->when($linea['ubicacion_id'], fn ($q, $u) => $q->where('existencias.ubicacion_id', $u))
            ->when($linea['lote_id'], fn ($q, $l) => $q->where('existencias.lote_id', $l));

        if ($producto->usa_lotes) {
            $consulta->leftJoin('lotes', 'lotes.id', '=', 'existencias.lote_id')
                ->when($excluirVencidos && ! $linea['lote_id'], fn ($q) => $q->where(fn ($w) => $w
                    ->whereNull('lotes.fecha_vencimiento')
                    ->orWhere('lotes.fecha_vencimiento', '>=', today()->toDateString())))
                ->orderByRaw('lotes.fecha_vencimiento IS NULL')
                ->orderBy('lotes.fecha_vencimiento')
                ->orderBy('existencias.lote_id');
        }

        $existencias = $consulta->orderByRaw('existencias.ubicacion_id IS NOT NULL')->orderBy('existencias.id')->get();

        $restante = -$linea['cantidad'];
        $partes = [];

        foreach ($existencias as $existencia) {
            if ($restante <= self::TOLERANCIA) {
                break;
            }

            $tomar = min((float) $existencia->cantidad, $restante);
            $partes[] = [
                'ubicacion_id' => $existencia->ubicacion_id,
                'lote_id' => $existencia->lote_id,
                'cantidad' => -round($tomar, 4),
                'series' => null,
            ];
            $restante = round($restante - $tomar, 4);
        }

        // Lo que falte sale de la fila indicada (queda negativo si el depósito lo permite; si no, da error)
        if ($restante > self::TOLERANCIA) {
            $indice = collect($partes)->search(fn ($p) => $p['ubicacion_id'] === $linea['ubicacion_id'] && $p['lote_id'] === $linea['lote_id']);

            if ($indice !== false) {
                $partes[$indice]['cantidad'] = round($partes[$indice]['cantidad'] - $restante, 4);
            } else {
                $partes[] = ['ubicacion_id' => $linea['ubicacion_id'], 'lote_id' => $linea['lote_id'], 'cantidad' => -$restante, 'series' => null];
            }
        }

        return $partes;
    }

    /** Salida de un producto con series: se agrupa según dónde está cada serie. */
    protected function partesPorSeries(array $linea, Producto $producto, Deposito $deposito, array &$errores): ?array
    {
        $series = Serie::query()
            ->where('producto_id', $producto->id)
            ->whereIn('numero', $linea['series'])
            ->get()
            ->keyBy('numero');

        $conError = false;
        $grupos = [];

        foreach ($linea['series'] as $numero) {
            $serie = $series->get($numero);

            $problema = match (true) {
                ! $serie => 'no existe',
                ! $serie->enStock() => 'ya salió del stock',
                $serie->deposito_id !== $deposito->id => 'no está en ' . $deposito->nombre,
                $linea['ubicacion_id'] && $serie->ubicacion_id !== $linea['ubicacion_id'] => 'no está en esa ubicación',
                $linea['lote_id'] && $serie->lote_id !== $linea['lote_id'] => 'no es de ese lote',
                default => null,
            };

            if ($problema) {
                $errores[] = "{$producto->nombre}: la serie {$numero} {$problema}.";
                $conError = true;
                continue;
            }

            $clave = ($serie->ubicacion_id ?? 0) . '-' . ($serie->lote_id ?? 0);
            $grupos[$clave] ??= ['ubicacion_id' => $serie->ubicacion_id, 'lote_id' => $serie->lote_id, 'cantidad' => 0, 'series' => []];
            $grupos[$clave]['cantidad']--;
            $grupos[$clave]['series'][] = $numero;
        }

        return $conError ? null : array_values($grupos);
    }

    /**
     * Actualiza el estado de las series de una parte. Devuelve los IDs, o null si hubo errores.
     */
    protected function moverSeries(Producto $producto, Deposito $deposito, array $parte, array &$errores): ?array
    {
        $existentes = Serie::query()
            ->where('producto_id', $producto->id)
            ->whereIn('numero', $parte['series'])
            ->get()
            ->keyBy('numero');

        $ids = [];
        $conError = false;

        foreach ($parte['series'] as $numero) {
            $serie = $existentes->get($numero);

            if ($parte['cantidad'] > 0) {
                if ($serie && $serie->enStock()) {
                    $errores[] = "{$producto->nombre}: la serie {$numero} ya está en stock"
                        . ($serie->deposito_id ? ' en ' . ($serie->deposito?->nombre ?? 'otro depósito') : '') . '.';
                    $conError = true;
                    continue;
                }

                $serie ??= new Serie(['producto_id' => $producto->id, 'numero' => $numero]);
                $serie->fill([
                    'estado' => Serie::EN_STOCK,
                    'deposito_id' => $deposito->id,
                    'ubicacion_id' => $parte['ubicacion_id'],
                    'lote_id' => $parte['lote_id'],
                ])->save();
            } else {
                $serie->fill([
                    'estado' => Serie::EGRESADO,
                    'deposito_id' => null,
                    'ubicacion_id' => null,
                ])->save();
            }

            $ids[] = $serie->id;
        }

        return $conError ? null : $ids;
    }

    /** Saldo de un producto en un depósito, opcionalmente filtrado por ubicación y/o lote. */
    protected function saldoFiltrado(int $productoId, int $depositoId, ?int $ubicacionId, ?int $loteId, float $saldoDeposito): float
    {
        if (! $ubicacionId && ! $loteId) {
            return $saldoDeposito;
        }

        return (float) Existencia::query()
            ->where('producto_id', $productoId)
            ->where('deposito_id', $depositoId)
            ->when($ubicacionId, fn ($q) => $q->where('ubicacion_id', $ubicacionId))
            ->when($loteId, fn ($q) => $q->where('lote_id', $loteId))
            ->sum('cantidad');
    }

    protected function recalcularPromedio(Producto $producto, float $stockPrevio, float $cantidad, float $costo): void
    {
        $promedio = (float) $producto->costo_promedio;
        $nuevoStock = $stockPrevio + $cantidad;

        if ($cantidad > 0 && $stockPrevio <= self::TOLERANCIA) {
            $nuevo = $costo;
        } elseif ($nuevoStock <= self::TOLERANCIA) {
            return;
        } else {
            $nuevo = ($stockPrevio * $promedio + $cantidad * $costo) / $nuevoStock;
        }

        if ($nuevo >= 0) {
            $producto->costo_promedio = round($nuevo, 4);
        }
    }

    protected function costoVigente(Producto $producto): float
    {
        $promedio = (float) $producto->costo_promedio;

        return $promedio > 0 ? $promedio : (float) $producto->costo;
    }

    protected function mensajeInsuficiente(Producto $producto, Deposito $deposito, array $linea, array $parte, float $actual, float $saldoAntes, bool $excluirVencidos): string
    {
        $unidad = $producto->unidad?->abreviatura;

        if ($linea['ubicacion_id'] || $linea['lote_id'] || $linea['exacta']) {
            $donde = collect([
                $parte['ubicacion_id'] ? 'ubicación ' . (Ubicacion::find($parte['ubicacion_id'])?->codigo ?? '?') : 'sin ubicar',
                $parte['lote_id'] ? 'lote ' . (Lote::find($parte['lote_id'])?->codigo ?? '?') : null,
            ])->filter()->implode(', ');

            return "Stock insuficiente de {$producto->nombre} en {$deposito->nombre} ({$donde}): hay "
                . Formato::cantidad($actual) . " {$unidad} y se necesitan " . Formato::cantidad(abs($parte['cantidad'])) . " {$unidad}.";
        }

        $nota = $producto->usa_lotes && $excluirVencidos
            ? ' Los lotes vencidos no se descuentan solos: si querés sacarlos, elegí el lote.'
            : '';

        return "Stock insuficiente de {$producto->nombre} en {$deposito->nombre}: hay "
            . Formato::cantidad($saldoAntes) . " {$unidad} y se necesitan " . Formato::cantidad(abs($linea['cantidad'])) . " {$unidad}.{$nota}";
    }

    /* =========================================================
       Validación de las líneas
       ========================================================= */

    protected function normalizar(array $lineas, int $depositoId, Empresa $empresa, ?User $usuario, bool $esAnulacion, bool $interno = false): array
    {
        $decimales = (int) $empresa->decimales_cantidad;
        $lineas = array_values($lineas);

        $productos = Producto::query()
            ->when($esAnulacion, fn ($q) => $q->withTrashed())
            ->with('unidad')
            ->whereIn('id', collect($lineas)->pluck('producto_id')->filter()->map(fn ($id) => (int) $id)->unique())
            ->get()
            ->keyBy('id');

        $depositoIds = collect($lineas)->map(fn ($l) => (int) ($l['deposito_id'] ?? $depositoId))->push($depositoId)->unique();
        $depositos = Deposito::query()
            ->when($esAnulacion, fn ($q) => $q->withTrashed())
            ->whereIn('id', $depositoIds)
            ->get()
            ->keyBy('id');

        $ubicaciones = Ubicacion::query()
            ->whereIn('id', collect($lineas)->pluck('ubicacion_id')->filter()->map(fn ($id) => (int) $id)->unique())
            ->get()
            ->keyBy('id');

        $lotes = Lote::query()
            ->whereIn('id', collect($lineas)->pluck('lote_id')->filter()->map(fn ($id) => (int) $id)->unique())
            ->get()
            ->keyBy('id');

        // El depósito "En tránsito" solo lo mueven las transferencias, y no depende de los permisos del usuario
        $permisos = [];
        $puedeOperar = function (Deposito $deposito) use ($usuario, &$permisos, $interno): bool {
            if ($deposito->es_transito) {
                return $interno;
            }

            return $usuario === null || ($permisos[$deposito->id] ??= $usuario->puedeOperarDeposito($deposito->id));
        };
        $sinPermiso = fn (Deposito $deposito) => $deposito->es_transito
            ? 'El depósito "En tránsito" lo maneja el sistema: usá Transferencias.'
            : "No tenés permiso para operar el depósito {$deposito->nombre}.";

        $errores = [];

        $cabecera = $depositos->get($depositoId);
        if (! $cabecera || (! $esAnulacion && ! $cabecera->activo)) {
            $errores[] = 'El depósito elegido no existe o está inactivo.';
        } elseif (! $puedeOperar($cabecera)) {
            $errores[] = $sinPermiso($cabecera);
        }

        if (! $lineas) {
            $errores[] = 'El movimiento no tiene productos.';
        }

        $resultado = [];

        foreach ($lineas as $i => $linea) {
            $n = $i + 1;
            $producto = $productos->get((int) ($linea['producto_id'] ?? 0));

            if (! $producto) {
                $errores[] = "Renglón {$n}: el producto no existe.";
                continue;
            }

            if (! $esAnulacion && ! $producto->manejaStock()) {
                $errores[] = "{$producto->nombre}: no controla stock.";
                continue;
            }

            $espejoDe = isset($linea['espejo_de']) ? (int) $linea['espejo_de'] : null;

            if ($espejoDe !== null && ($espejoDe >= $i || ! isset($lineas[$espejoDe]))) {
                $errores[] = "Renglón {$n}: referencia de transferencia inválida.";
                continue;
            }

            $esConteo = array_key_exists('contado', $linea);
            $cantidad = round((float) ($linea['cantidad'] ?? 0), $decimales);
            $contado = null;

            if ($esConteo) {
                if ($linea['contado'] === null || $linea['contado'] === '' || (float) $linea['contado'] < 0) {
                    $errores[] = "{$producto->nombre}: la cantidad contada tiene que ser cero o más.";
                    continue;
                }

                $contado = round((float) $linea['contado'], $decimales);
            } elseif (abs($cantidad) < self::TOLERANCIA) {
                $errores[] = "{$producto->nombre}: la cantidad no puede ser cero.";
                continue;
            }

            $aRevisar = $esConteo ? $contado : $cantidad;

            if (! $esAnulacion && ! $producto->unidad?->permite_decimales && abs($aRevisar - round($aRevisar)) > self::TOLERANCIA) {
                $errores[] = "{$producto->nombre}: la unidad " . ($producto->unidad?->abreviatura ?? '') . ' no admite decimales.';
                continue;
            }

            $deposito = $depositos->get((int) ($linea['deposito_id'] ?? $depositoId));

            if (! $deposito || (! $esAnulacion && ! $deposito->activo)) {
                $errores[] = "{$producto->nombre}: el depósito no existe o está inactivo.";
                continue;
            }

            if (! $puedeOperar($deposito)) {
                $errores[] = $sinPermiso($deposito);
                continue;
            }

            $ubicacionId = ! empty($linea['ubicacion_id']) ? (int) $linea['ubicacion_id'] : null;

            if ($ubicacionId) {
                $ubicacion = $ubicaciones->get($ubicacionId);

                if (! $ubicacion || $ubicacion->deposito_id !== $deposito->id) {
                    $errores[] = "{$producto->nombre}: la ubicación no pertenece a {$deposito->nombre}.";
                    continue;
                }

                if (! $esAnulacion && $cantidad > 0 && ! $ubicacion->activa) {
                    $errores[] = "{$producto->nombre}: la ubicación {$ubicacion->codigo} está inactiva.";
                    continue;
                }
            }

            // ----- Lote
            $loteId = null;
            $loteNuevo = null;

            if (! empty($linea['lote_id'])) {
                $lote = $lotes->get((int) $linea['lote_id']);

                if (! $lote || $lote->producto_id !== $producto->id) {
                    $errores[] = "{$producto->nombre}: el lote elegido no corresponde al producto.";
                    continue;
                }

                $loteId = $lote->id;
            } elseif ($producto->usa_lotes && filled($linea['lote_codigo'] ?? null) && $espejoDe === null) {
                $codigo = mb_strtoupper(trim((string) $linea['lote_codigo']));
                $vencimiento = $this->fechaOpcional($linea['lote_vencimiento'] ?? null);

                if (filled($linea['lote_vencimiento'] ?? null) && ! $vencimiento) {
                    $errores[] = "{$producto->nombre}: la fecha de vencimiento del lote {$codigo} no es válida.";
                    continue;
                }

                $lote = Lote::where('producto_id', $producto->id)->where('codigo', $codigo)->first();

                if ($lote) {
                    if ($vencimiento && $lote->fecha_vencimiento && ! $lote->fecha_vencimiento->isSameDay($vencimiento)) {
                        $errores[] = "{$producto->nombre}: el lote {$codigo} ya existe con vencimiento " . $lote->fecha_vencimiento->format('d/m/Y') . '.';
                        continue;
                    }

                    $loteId = $lote->id;
                } elseif (! $esConteo && $cantidad < 0) {
                    $errores[] = "{$producto->nombre}: el lote {$codigo} no existe.";
                    continue;
                } else {
                    $loteNuevo = ['codigo' => $codigo, 'vencimiento' => $vencimiento?->toDateString()];
                }
            }

            // ----- Series
            $series = null;

            if ($espejoDe === null && ! empty($linea['series'])) {
                $series = Serie::parsear($linea['series']);
            }

            if ($producto->usa_series && $espejoDe === null && ! $esAnulacion) {
                if ($esConteo) {
                    $errores[] = "{$producto->nombre}: los productos con número de serie no se ajustan por conteo; usá un ajuste por diferencia indicando las series.";
                    continue;
                }

                $unidades = (int) round(abs($cantidad));

                if (count($series ?? []) !== $unidades) {
                    $errores[] = "{$producto->nombre}: cargaste " . count($series ?? []) . " números de serie para {$unidades} unidades.";
                    continue;
                }
            } elseif (! $producto->usa_series && ! $esAnulacion) {
                $series = null; // un producto sin series ignora lo que venga
            }

            $costo = $linea['costo_unitario'] ?? null;

            $resultado[$i] = [
                'producto_id' => $producto->id,
                'deposito_id' => $deposito->id,
                'ubicacion_id' => $ubicacionId,
                'lote_id' => $loteId,
                'lote_nuevo' => $loteNuevo,
                'cantidad' => $cantidad,
                'contado' => $contado,
                'series' => $series,
                'espejo_de' => $espejoDe,
                'costo_unitario' => ($costo === null || $costo === '') ? null : max(0.0, (float) $costo),
                'presentacion_id' => ! empty($linea['presentacion_id']) ? (int) $linea['presentacion_id'] : null,
                'cantidad_presentacion' => isset($linea['cantidad_presentacion']) && $linea['cantidad_presentacion'] !== ''
                    ? (float) $linea['cantidad_presentacion'] : null,
                'exacta' => (bool) ($linea['exacta'] ?? false),
            ];
        }

        if ($errores) {
            throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores))]);
        }

        return $resultado;
    }

    protected function fechaOpcional(mixed $valor): ?Carbon
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function conSigno(array $lineas, int $signo): array
    {
        return array_map(function ($linea) use ($signo) {
            $linea['cantidad'] = $signo * abs((float) ($linea['cantidad'] ?? 0));

            return $linea;
        }, array_values($lineas));
    }
}
