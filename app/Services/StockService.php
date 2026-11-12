<?php

namespace App\Services;

use App\Events\StockActualizado;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Existencia;
use App\Models\Movimiento;
use App\Models\Numerador;
use App\Models\Producto;
use App\Models\Ubicacion;
use App\Models\User;
use App\Support\EmpresaActual;
use App\Support\Formato;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Motor de stock. Es el ÚNICO lugar del sistema que modifica existencias.
 *
 * Garantías:
 * - Todo o nada: cada movimiento corre en una transacción.
 * - Sin stock fantasma: bloquea (SELECT … FOR UPDATE) los productos involucrados,
 *   así dos operaciones simultáneas sobre el mismo producto se ejecutan una detrás de otra.
 * - Sin duplicados: si llega dos veces la misma referencia_externa, devuelve el movimiento ya registrado.
 * - Kardex con saldo por depósito y costo promedio ponderado actualizado.
 *
 * Formato de cada línea:
 *   producto_id, cantidad (con signo en registrar/ajustar; positiva en ingresar/egresar),
 *   deposito_id?, ubicacion_id?, lote_id?, costo_unitario?, presentacion_id?, cantidad_presentacion?
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
     * La diferencia contra el sistema se calcula con el producto bloqueado, así no se pisa con otra operación.
     * Con ubicacion_id compara contra esa ubicación; sin ubicación, contra el total del depósito.
     * Las líneas sin diferencia no se registran.
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
     * Transferencia directa entre depósitos (sale de uno y entra en el otro en el mismo movimiento).
     * Cada línea admite ubicacion_id (origen) y ubicacion_destino_id.
     */
    public function transferir(int $origenId, int $destinoId, array $lineas, array $opciones = [], ?User $usuario = null): Movimiento
    {
        if ($origenId === $destinoId) {
            throw ValidationException::withMessages([
                'deposito_destino_id' => 'El depósito de destino tiene que ser distinto al de origen.',
            ]);
        }

        $renglones = [];

        foreach ($lineas as $linea) {
            $cantidad = abs((float) ($linea['cantidad'] ?? 0));
            $base = [
                'producto_id' => $linea['producto_id'] ?? null,
                'lote_id' => $linea['lote_id'] ?? null,
            ];

            $renglones[] = $base + ['deposito_id' => $origenId, 'ubicacion_id' => $linea['ubicacion_id'] ?? null, 'cantidad' => -$cantidad];
            $renglones[] = $base + ['deposito_id' => $destinoId, 'ubicacion_id' => $linea['ubicacion_destino_id'] ?? null, 'cantidad' => $cantidad];
        }

        return $this->registrar(
            Movimiento::TIPO_TRANSFERENCIA,
            $origenId,
            $renglones,
            ['deposito_destino_id' => $destinoId] + $opciones,
            $usuario
        );
    }

    /**
     * Anula un movimiento generando el contramovimiento (nunca se borra nada).
     * Si el stock que entró ya se consumió y el depósito no permite negativo, no deja anular.
     */
    public function anular(Movimiento $movimiento, ?User $usuario = null, ?string $motivo = null): Movimiento
    {
        return DB::transaction(function () use ($movimiento, $usuario, $motivo) {
            $original = Movimiento::query()->whereKey($movimiento->id)->lockForUpdate()->firstOrFail();

            if (! $original->puedeAnularse()) {
                throw ValidationException::withMessages([
                    'movimiento' => 'Este movimiento ya está anulado o no se puede anular.',
                ]);
            }

            $lineas = $original->items()->get()->map(fn ($item) => [
                'producto_id' => $item->producto_id,
                'deposito_id' => $item->deposito_id,
                'ubicacion_id' => $item->ubicacion_id,
                'lote_id' => $item->lote_id,
                'cantidad' => -(float) $item->cantidad,
                'costo_unitario' => (float) $item->costo_unitario,
                'exacta' => true,
            ])->all();

            $motivo = filled($motivo) ? trim($motivo) : null;

            $contra = $this->registrar(Movimiento::TIPO_ANULACION, $original->deposito_id, $lineas, [
                'deposito_destino_id' => $original->deposito_destino_id,
                'anula_a_id' => $original->id,
                'origen' => $usuario ? 'manual' : 'sistema',
                'observaciones' => trim("Anulación del movimiento N° {$original->numero_texto}. " . ($motivo ?? '')),
                'revertir_costo' => $original->tipo !== Movimiento::TIPO_TRANSFERENCIA,
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
     * Stock de varios productos en un depósito, total y por ubicación (0 = sin ubicar):
     * [producto_id => ['total' => 12.0, 'ubicaciones' => [0 => 2.0, 15 => 10.0]]]
     */
    public function resumen(array $productoIds, int $depositoId): array
    {
        if (! $productoIds) {
            return [];
        }

        $resultado = [];

        Existencia::query()
            ->whereIn('producto_id', $productoIds)
            ->where('deposito_id', $depositoId)
            ->selectRaw('producto_id, ubicacion_id, SUM(cantidad) AS total')
            ->groupBy('producto_id', 'ubicacion_id')
            ->get()
            ->each(function ($fila) use (&$resultado) {
                $id = $fila->producto_id;
                $resultado[$id]['total'] = round(($resultado[$id]['total'] ?? 0) + (float) $fila->total, 4);
                $resultado[$id]['ubicaciones'][(int) ($fila->ubicacion_id ?? 0)] = (float) $fila->total;
            });

        return $resultado;
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
     *           deposito_destino_id, anula_a_id, revertir_costo (uso interno de anular)
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
        $lineas = $this->normalizar($lineas, $depositoId, $empresa, $usuario, $esAnulacion);

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
        $fecha = now();
        $errores = [];
        $items = [];

        // 3) Aplicar cada línea
        foreach ($lineas as $linea) {
            $producto = $productos->get($linea['producto_id']);
            $deposito = $depositos->get($linea['deposito_id']);
            $clave = "{$producto->id}-{$deposito->id}";
            $saldoAntes = $saldoDeposito[$clave] ?? 0.0;

            // Ajuste por conteo: la cantidad es la diferencia entre lo contado y lo que dice el sistema
            if ($linea['contado'] !== null) {
                $enSistema = ($linea['ubicacion_id'] || $linea['lote_id'])
                    ? (float) (Existencia::where('clave', Existencia::clave($producto->id, $deposito->id, $linea['ubicacion_id'], $linea['lote_id']))->value('cantidad') ?? 0)
                    : $saldoAntes;

                $linea['cantidad'] = round($linea['contado'] - $enSistema, 4);

                if (abs($linea['cantidad']) < self::TOLERANCIA) {
                    continue;
                }
            }

            foreach ($this->partes($linea, $producto, $deposito) as $parte) {
                $existencia = Existencia::para($producto->id, $deposito->id, $parte['ubicacion_id'], $parte['lote_id']);
                $actual = (float) $existencia->cantidad;
                $nueva = round($actual + $parte['cantidad'], 4);

                if ($parte['cantidad'] < 0 && $nueva < -self::TOLERANCIA && ! $deposito->permiteStockNegativo()) {
                    $errores[] = $this->mensajeInsuficiente($producto, $deposito, $linea, $parte, $actual, $saldoAntes);
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
                ];
            }
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
        ]);

        foreach ($items as $item) {
            $movimiento->items()->create($item);
        }

        event(new StockActualizado($movimiento->empresa_id, $movimiento->id, $productoIds, $depositoIds));

        return $movimiento->load('items');
    }

    /**
     * Divide una salida sin ubicación entre las ubicaciones que tienen stock:
     * primero lo "sin ubicar", después el resto por orden de alta.
     */
    protected function partes(array $linea, Producto $producto, Deposito $deposito): array
    {
        $simple = [[
            'ubicacion_id' => $linea['ubicacion_id'],
            'lote_id' => $linea['lote_id'],
            'cantidad' => $linea['cantidad'],
        ]];

        if ($linea['cantidad'] > 0 || $linea['exacta'] || $linea['ubicacion_id'] || $linea['lote_id']) {
            return $simple;
        }

        $restante = -$linea['cantidad'];
        $partes = [];

        $existencias = Existencia::query()
            ->where('producto_id', $producto->id)
            ->where('deposito_id', $deposito->id)
            ->where('cantidad', '>', 0)
            ->orderByRaw('ubicacion_id IS NOT NULL')
            ->orderBy('id')
            ->get();

        foreach ($existencias as $existencia) {
            if ($restante <= self::TOLERANCIA) {
                break;
            }

            $tomar = min((float) $existencia->cantidad, $restante);
            $partes[] = [
                'ubicacion_id' => $existencia->ubicacion_id,
                'lote_id' => $existencia->lote_id,
                'cantidad' => -round($tomar, 4),
            ];
            $restante = round($restante - $tomar, 4);
        }

        // Lo que falte sale de "sin ubicar" (queda negativo si el depósito lo permite; si no, da error)
        if ($restante > self::TOLERANCIA) {
            $indice = collect($partes)->search(fn ($p) => $p['ubicacion_id'] === null && $p['lote_id'] === null);

            if ($indice !== false) {
                $partes[$indice]['cantidad'] = round($partes[$indice]['cantidad'] - $restante, 4);
            } else {
                $partes[] = ['ubicacion_id' => null, 'lote_id' => null, 'cantidad' => -$restante];
            }
        }

        return $partes;
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

    protected function mensajeInsuficiente(Producto $producto, Deposito $deposito, array $linea, array $parte, float $actual, float $saldoAntes): string
    {
        $unidad = $producto->unidad?->abreviatura;
        $pedido = Formato::cantidad(abs($linea['cantidad']));

        if ($linea['ubicacion_id'] || $linea['lote_id'] || $linea['exacta']) {
            $donde = $parte['ubicacion_id']
                ? 'ubicación ' . (Ubicacion::find($parte['ubicacion_id'])?->codigo ?? '?')
                : 'sin ubicar';

            return "Stock insuficiente de {$producto->nombre} en {$deposito->nombre} ({$donde}): hay "
                . Formato::cantidad($actual) . " {$unidad} y se necesitan " . Formato::cantidad(abs($parte['cantidad'])) . " {$unidad}.";
        }

        return "Stock insuficiente de {$producto->nombre} en {$deposito->nombre}: hay "
            . Formato::cantidad($saldoAntes) . " {$unidad} y se necesitan {$pedido} {$unidad}.";
    }

    /* =========================================================
       Validación de las líneas
       ========================================================= */

    protected function normalizar(array $lineas, int $depositoId, Empresa $empresa, ?User $usuario, bool $esAnulacion): array
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

        $permisos = [];
        $puedeOperar = function (int $id) use ($usuario, &$permisos): bool {
            return $usuario === null || ($permisos[$id] ??= $usuario->puedeOperarDeposito($id));
        };

        $errores = [];

        $cabecera = $depositos->get($depositoId);
        if (! $cabecera || (! $esAnulacion && ! $cabecera->activo)) {
            $errores[] = 'El depósito elegido no existe o está inactivo.';
        } elseif (! $puedeOperar($depositoId)) {
            $errores[] = "No tenés permiso para operar el depósito {$cabecera->nombre}.";
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

            if (! $puedeOperar($deposito->id)) {
                $errores[] = "No tenés permiso para operar el depósito {$deposito->nombre}.";
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

            $costo = $linea['costo_unitario'] ?? null;

            $resultado[] = [
                'producto_id' => $producto->id,
                'deposito_id' => $deposito->id,
                'ubicacion_id' => $ubicacionId,
                'lote_id' => ! empty($linea['lote_id']) ? (int) $linea['lote_id'] : null,
                'cantidad' => $cantidad,
                'contado' => $contado,
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

    protected function conSigno(array $lineas, int $signo): array
    {
        return array_map(function ($linea) use ($signo) {
            $linea['cantidad'] = $signo * abs((float) ($linea['cantidad'] ?? 0));

            return $linea;
        }, array_values($lineas));
    }
}
