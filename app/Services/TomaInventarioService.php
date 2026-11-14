<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\Numerador;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\TomaInventario;
use App\Models\TomaInventarioItem;
use App\Models\Ubicacion;
use App\Models\User;
use App\Support\EmpresaActual;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inventario físico (tomas de inventario).
 *
 * Cómo calcula las diferencias:
 * - Al iniciar se arma la lista con lo que el sistema tiene en el alcance elegido (producto + ubicación + lote).
 * - Cada vez que se guarda el conteo de un renglón, se toma en ese momento lo que dice el sistema ("teórico").
 * - Al cerrar se ajusta la DIFERENCIA (contado − teórico al contar). Así, si el depósito no se congeló,
 *   las ventas o ingresos que pasaron después de contar un renglón no se pisan.
 * - Si la toma congela el depósito, nadie puede mover stock ahí hasta cerrarla o cancelarla.
 *
 * El ajuste lo registra el StockService (único que escribe existencias, lotes y series).
 */
class TomaInventarioService
{
    public function __construct(protected StockService $stock)
    {
    }

    /* =========================================================
       Iniciar
       ========================================================= */

    /**
     * @param  array  $datos  deposito_id, ubicacion_id?, categoria_id?, descripcion?, congela?, a_ciegas?, incluir_sin_stock?, observaciones?
     */
    public function iniciar(array $datos, ?User $usuario = null): TomaInventario
    {
        $deposito = Deposito::activos()->find((int) ($datos['deposito_id'] ?? 0));

        if (! $deposito) {
            throw ValidationException::withMessages(['deposito_id' => 'El depósito no existe o está inactivo.']);
        }

        if ($usuario && ! $usuario->puedeOperarDeposito($deposito->id)) {
            throw ValidationException::withMessages(['deposito_id' => "No tenés permiso para operar {$deposito->nombre}."]);
        }

        $ubicacion = null;
        if (! empty($datos['ubicacion_id'])) {
            $ubicacion = Ubicacion::where('deposito_id', $deposito->id)->find((int) $datos['ubicacion_id']);

            if (! $ubicacion) {
                throw ValidationException::withMessages(['ubicacion_id' => "La ubicación no pertenece a {$deposito->nombre}."]);
            }
        }

        $categoria = ! empty($datos['categoria_id']) ? Categoria::find((int) $datos['categoria_id']) : null;

        return DB::transaction(function () use ($datos, $deposito, $ubicacion, $categoria, $usuario) {
            // Una sola toma en curso por depósito (bloqueo para que dos pedidos simultáneos no creen dos)
            Deposito::whereKey($deposito->id)->lockForUpdate()->first();

            if ($enCurso = TomaInventario::enCurso()->where('deposito_id', $deposito->id)->first()) {
                throw ValidationException::withMessages([
                    'deposito_id' => "Ya hay una toma de inventario en curso en {$deposito->nombre} (N° {$enCurso->numero_texto}). Cerrala o cancelala primero.",
                ]);
            }

            $toma = TomaInventario::create([
                'numero' => Numerador::siguiente('toma_inventario'),
                'deposito_id' => $deposito->id,
                'ubicacion_id' => $ubicacion?->id,
                'categoria_id' => $categoria?->id,
                'descripcion' => $datos['descripcion'] ?? null,
                'estado' => TomaInventario::EN_CURSO,
                'congela' => (bool) ($datos['congela'] ?? true),
                'a_ciegas' => (bool) ($datos['a_ciegas'] ?? false),
                'incluir_sin_stock' => (bool) ($datos['incluir_sin_stock'] ?? false),
                'observaciones' => $datos['observaciones'] ?? null,
                'user_id' => $usuario?->id,
            ]);

            $this->armarLista($toma, $ubicacion, $categoria);

            return $toma->load('items');
        });
    }

    protected function armarLista(TomaInventario $toma, ?Ubicacion $ubicacion, ?Categoria $categoria): void
    {
        $ubicacionIds = $ubicacion ? [$ubicacion->id, ...$ubicacion->descendientesIds()] : null;
        $categoriaIds = $categoria ? [$categoria->id, ...$categoria->descendientesIds()] : null;

        $filas = Existencia::query()
            ->where('deposito_id', $toma->deposito_id)
            ->where('cantidad', '!=', 0)
            ->when($ubicacionIds, fn ($q) => $q->whereIn('ubicacion_id', $ubicacionIds))
            ->selectRaw('producto_id, ubicacion_id, lote_id, SUM(cantidad) AS total')
            ->groupBy('producto_id', 'ubicacion_id', 'lote_id')
            ->get();

        $productos = Producto::query()
            ->stockeables()
            ->whereIn('id', $filas->pluck('producto_id')->unique())
            ->when($categoriaIds, fn ($q) => $q->whereIn('categoria_id', $categoriaIds))
            ->get()
            ->keyBy('id');

        // Series en stock del depósito, agrupadas por producto-ubicación-lote
        $series = Serie::query()
            ->where('deposito_id', $toma->deposito_id)
            ->where('estado', Serie::EN_STOCK)
            ->whereIn('producto_id', $productos->where('usa_series', true)->keys())
            ->when($ubicacionIds, fn ($q) => $q->whereIn('ubicacion_id', $ubicacionIds))
            ->orderBy('numero')
            ->get(['producto_id', 'ubicacion_id', 'lote_id', 'numero'])
            ->groupBy(fn ($s) => TomaInventarioItem::clave($s->producto_id, $s->ubicacion_id, $s->lote_id))
            ->map(fn ($g) => $g->pluck('numero')->values()->all());

        $ahora = now();
        $renglones = [];

        foreach ($filas as $fila) {
            $producto = $productos->get($fila->producto_id);

            if (! $producto) {
                continue;
            }

            $clave = TomaInventarioItem::clave($producto->id, $fila->ubicacion_id, $fila->lote_id);
            $renglones[$clave] = $this->renglon($toma, $producto, $fila->ubicacion_id, $fila->lote_id, (float) $fila->total, $series->get($clave), $clave, $ahora);
        }

        if ($toma->incluir_sin_stock) {
            $sinStock = Producto::query()
                ->activos()
                ->stockeables()
                ->when($categoriaIds, fn ($q) => $q->whereIn('categoria_id', $categoriaIds))
                ->whereNotIn('id', collect($renglones)->pluck('producto_id')->unique())
                ->get();

            foreach ($sinStock as $producto) {
                $clave = TomaInventarioItem::clave($producto->id, $ubicacion?->id, null);
                $renglones[$clave] = $this->renglon($toma, $producto, $ubicacion?->id, null, 0.0, $producto->usa_series ? [] : null, $clave, $ahora);
            }
        }

        foreach (array_chunk(array_values($renglones), 500) as $lote) {
            TomaInventarioItem::insert($lote);
        }
    }

    protected function renglon(TomaInventario $toma, Producto $producto, ?int $ubicacionId, ?int $loteId, float $teorico, ?array $series, string $clave, Carbon $ahora): array
    {
        return [
            'empresa_id' => $toma->empresa_id,
            'toma_inventario_id' => $toma->id,
            'producto_id' => $producto->id,
            'ubicacion_id' => $ubicacionId,
            'lote_id' => $loteId,
            'clave' => $clave,
            'teorico' => round($teorico, 4),
            'series_teoricas' => $producto->usa_series ? json_encode($series ?? []) : null,
            'costo_unitario' => round($producto->costo_valuacion, 4),
            'agregado' => false,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }

    /* =========================================================
       Contar
       ========================================================= */

    /**
     * Guarda lo contado.
     *
     * @param  array  $conteos  [item_id => ['contado' => 12] | ['series' => "S1\nS2"] | ['limpiar' => true]]
     * @return int  renglones guardados
     */
    public function registrarConteo(TomaInventario $toma, array $conteos, ?User $usuario = null): int
    {
        $this->verificarEnCurso($toma, $usuario);

        if (! $conteos) {
            return 0;
        }

        return DB::transaction(function () use ($toma, $conteos, $usuario) {
            $decimales = (int) (EmpresaActual::modelo()?->decimales_cantidad ?? 2);

            $items = $toma->items()
                ->with(['producto.unidad', 'lote'])
                ->whereIn('id', array_map('intval', array_keys($conteos)))
                ->lockForUpdate()
                ->get();

            $errores = [];
            $guardados = 0;

            foreach ($items as $item) {
                $dato = $conteos[$item->id] ?? [];
                $producto = $item->producto;

                if (! empty($dato['limpiar'])) {
                    $item->update(['contado' => null, 'series_contadas' => null, 'contado_por' => null, 'contado_at' => null]);
                    $guardados++;
                    continue;
                }

                if ($producto->usa_series) {
                    if (! array_key_exists('series', $dato) || $dato['series'] === null) {
                        continue;
                    }

                    $seriesContadas = Serie::parsear($dato['series']);
                    $contado = (float) count($seriesContadas);
                } else {
                    $valor = $dato['contado'] ?? null;

                    if ($valor === null || $valor === '') {
                        continue;
                    }

                    // Acepta "2,5" o "2.5"; si trae coma, los puntos son separadores de miles
                    $valor = trim((string) $valor);
                    if (str_contains($valor, ',')) {
                        $valor = str_replace(['.', ','], ['', '.'], $valor);
                    }

                    if (! is_numeric($valor) || (float) $valor < 0) {
                        $errores[] = "{$producto->nombre}: la cantidad contada tiene que ser un número, cero o más.";
                        continue;
                    }

                    $contado = round((float) $valor, $decimales);
                    $seriesContadas = null;

                    if (! $producto->unidad?->permite_decimales && abs($contado - round($contado)) > StockService::TOLERANCIA) {
                        $errores[] = "{$producto->nombre}: la unidad " . ($producto->unidad?->abreviatura ?? '') . ' no admite decimales.';
                        continue;
                    }
                }

                [$teorico, $seriesTeoricas] = $this->teoricoActual($toma, $item, $producto);

                if ($producto->usa_lotes && ! $item->lote_id && ! $item->lote_codigo && $contado > $teorico + StockService::TOLERANCIA) {
                    $errores[] = "{$producto->nombre}: sobran unidades pero el renglón no tiene lote. Agregá un renglón indicando el lote de lo que sobra.";
                    continue;
                }

                $item->update([
                    'teorico' => $teorico,
                    'series_teoricas' => $seriesTeoricas,
                    'contado' => $contado,
                    'series_contadas' => $seriesContadas,
                    'contado_por' => $usuario?->id,
                    'contado_at' => now(),
                ]);

                $guardados++;
            }

            if ($errores) {
                throw ValidationException::withMessages(['conteos' => array_values(array_unique($errores))]);
            }

            return $guardados;
        });
    }

    /**
     * Agrega un renglón que no estaba en la lista (algo que apareció en el conteo) y lo deja contado.
     * Si el renglón ya existe, solo actualiza lo contado.
     *
     * @param  array  $datos  producto_id, ubicacion_id?, lote_id? | lote_codigo? + lote_vencimiento?, contado? | series?
     */
    public function agregarRenglon(TomaInventario $toma, array $datos, ?User $usuario = null): TomaInventarioItem
    {
        $this->verificarEnCurso($toma, $usuario);

        $producto = Producto::activos()->stockeables()->find((int) ($datos['producto_id'] ?? 0));

        if (! $producto) {
            throw ValidationException::withMessages(['producto_id' => 'El producto no existe o no controla stock.']);
        }

        $toma->loadMissing(['deposito', 'ubicacion']);
        $deposito = $toma->deposito;
        $ubicacionId = ! empty($datos['ubicacion_id']) ? (int) $datos['ubicacion_id'] : null;

        if ($ubicacionId) {
            $ubicacion = Ubicacion::where('deposito_id', $deposito->id)->find($ubicacionId);

            if (! $ubicacion) {
                throw ValidationException::withMessages(['ubicacion_id' => "La ubicación no pertenece a {$deposito->nombre}."]);
            }
        }

        if ($toma->ubicacion_id) {
            $permitidas = [$toma->ubicacion_id, ...($toma->ubicacion?->descendientesIds() ?? [])];

            if (! in_array($ubicacionId, $permitidas, true)) {
                throw ValidationException::withMessages(['ubicacion_id' => 'Esta toma es solo de la ubicación ' . $toma->ubicacion?->codigo . ' (y sus sububicaciones).']);
            }
        }

        $loteId = null;
        $loteCodigo = null;
        $loteVencimiento = null;

        if ($producto->usa_lotes) {
            if (! empty($datos['lote_id'])) {
                $lote = Lote::where('producto_id', $producto->id)->find((int) $datos['lote_id']);

                if (! $lote) {
                    throw ValidationException::withMessages(['lote_id' => 'El lote no corresponde al producto.']);
                }

                $loteId = $lote->id;
            } elseif (filled($datos['lote_codigo'] ?? null)) {
                $codigo = mb_strtoupper(trim((string) $datos['lote_codigo']));
                $lote = Lote::where('producto_id', $producto->id)->where('codigo', $codigo)->first();

                if ($lote) {
                    $loteId = $lote->id;
                } else {
                    $loteCodigo = $codigo;
                    $loteVencimiento = filled($datos['lote_vencimiento'] ?? null) ? Carbon::parse($datos['lote_vencimiento'])->toDateString() : null;
                }
            } else {
                throw ValidationException::withMessages(['lote_codigo' => "{$producto->nombre} se maneja por lotes: indicá el lote."]);
            }
        }

        return DB::transaction(function () use ($toma, $producto, $ubicacionId, $loteId, $loteCodigo, $loteVencimiento, $datos, $usuario) {
            $clave = TomaInventarioItem::clave($producto->id, $ubicacionId, $loteId, $loteCodigo);
            $item = $toma->items()->where('clave', $clave)->first();

            if (! $item) {
                $item = new TomaInventarioItem([
                    'producto_id' => $producto->id,
                    'ubicacion_id' => $ubicacionId,
                    'lote_id' => $loteId,
                    'lote_codigo' => $loteCodigo,
                    'lote_vencimiento' => $loteVencimiento,
                    'clave' => $clave,
                    'costo_unitario' => round($producto->costo_valuacion, 4),
                    'agregado' => true,
                ]);
                [$item->teorico, $item->series_teoricas] = $this->teoricoActual($toma, $item, $producto);
                $item->toma_inventario_id = $toma->id;
                $item->save();
            }

            // Queda contado en el mismo paso (si el conteo no es válido, tampoco se agrega el renglón)
            $conteo = $producto->usa_series
                ? ['series' => (string) ($datos['series'] ?? '')]
                : ['contado' => $datos['contado'] ?? null];

            $this->registrarConteo($toma, [$item->id => $conteo], $usuario);

            return $item->fresh();
        });
    }

    /* =========================================================
       Cerrar / cancelar
       ========================================================= */

    /**
     * Cierra la toma y ajusta las diferencias en un solo movimiento.
     *
     * @param  string  $noContados  ignorar (no se tocan) | cero (lo que no se contó se da como faltante)
     */
    public function cerrar(TomaInventario $toma, string $noContados = 'ignorar', ?User $usuario = null, ?string $observaciones = null): TomaInventario
    {
        $noContados = $noContados === 'cero' ? 'cero' : 'ignorar';

        return DB::transaction(function () use ($toma, $noContados, $usuario, $observaciones) {
            $toma = TomaInventario::whereKey($toma->id)->lockForUpdate()->firstOrFail();
            $this->verificarEnCurso($toma, $usuario);

            $items = $toma->items()->with('producto.unidad')->get();

            // Lo no contado se cuenta como cero: el teórico es el de ahora
            if ($noContados === 'cero') {
                foreach ($items->whereNull('contado') as $item) {
                    [$teorico, $seriesTeoricas] = $this->teoricoActual($toma, $item, $item->producto);
                    $item->fill([
                        'teorico' => $teorico,
                        'series_teoricas' => $seriesTeoricas,
                        'contado' => 0,
                        'series_contadas' => $item->producto->usa_series ? [] : null,
                    ])->save();
                }
            }

            $salidas = [];
            $entradas = [];
            $faltante = 0.0;
            $sobrante = 0.0;
            $conDiferencia = 0;

            foreach ($items as $item) {
                if ($item->contado === null) {
                    continue;
                }

                $base = [
                    'producto_id' => $item->producto_id,
                    'ubicacion_id' => $item->ubicacion_id,
                    'lote_id' => $item->lote_id,
                    'lote_codigo' => $item->lote_codigo,
                    'lote_vencimiento' => $item->lote_vencimiento?->toDateString(),
                    'exacta' => true,
                ];

                if ($item->producto->usa_series) {
                    $teoricas = $item->series_teoricas ?? [];
                    $contadas = $item->series_contadas ?? [];
                    $faltan = array_values(array_diff($teoricas, $contadas));
                    $sobran = array_values(array_diff($contadas, $teoricas));

                    if ($faltan) {
                        $salidas[] = ['cantidad' => -count($faltan), 'series' => $faltan] + $base;
                    }
                    if ($sobran) {
                        $entradas[] = ['cantidad' => count($sobran), 'series' => $sobran] + $base;
                    }

                    $ajuste = count($sobran) - count($faltan);
                    $faltante += count($faltan) * (float) $item->costo_unitario;
                    $sobrante += count($sobran) * (float) $item->costo_unitario;
                    $hayDiferencia = $faltan || $sobran;
                } else {
                    $ajuste = round((float) $item->contado - (float) $item->teorico, 4);
                    $hayDiferencia = abs($ajuste) > StockService::TOLERANCIA;

                    if ($hayDiferencia) {
                        if ($ajuste < 0) {
                            $salidas[] = ['cantidad' => $ajuste] + $base;
                            $faltante += -$ajuste * (float) $item->costo_unitario;
                        } else {
                            $entradas[] = ['cantidad' => $ajuste] + $base;
                            $sobrante += $ajuste * (float) $item->costo_unitario;
                        }
                    }
                }

                $item->update(['ajuste' => $hayDiferencia ? $ajuste : 0]);
                $conDiferencia += $hayDiferencia ? 1 : 0;
            }

            $movimiento = null;
            $lineas = [...$salidas, ...$entradas]; // primero lo que falta: así una serie que cambió de lugar sale y vuelve a entrar

            if ($lineas) {
                $motivoId = Motivo::firstOrCreate(
                    ['tipo' => Movimiento::TIPO_AJUSTE, 'nombre' => 'Diferencia de inventario'],
                    ['pide_costo' => false, 'requiere_observacion' => false, 'activo' => true, 'orden' => 0]
                )->id;

                $movimiento = $this->stock->ajustar($toma->deposito_id, $lineas, [
                    'motivo_id' => $motivoId,
                    'origen' => 'inventario',
                    'toma_inventario_id' => $toma->id,
                    'interno_inventario' => true,
                    'comprobante' => "Toma de inventario N° {$toma->numero_texto}",
                    'observaciones' => $observaciones ?: $toma->descripcion,
                ], $usuario);
            }

            $toma->update([
                'estado' => TomaInventario::CERRADA,
                'no_contados' => $noContados,
                'movimiento_id' => $movimiento?->id,
                'valor_faltante' => round($faltante, 2),
                'valor_sobrante' => round($sobrante, 2),
                'lineas_con_diferencia' => $conDiferencia,
                'cerrada_at' => now(),
                'cerrada_por' => $usuario?->id,
            ]);

            return $toma->fresh();
        });
    }

    public function cancelar(TomaInventario $toma, string $motivo, ?User $usuario = null): TomaInventario
    {
        return DB::transaction(function () use ($toma, $motivo, $usuario) {
            $toma = TomaInventario::whereKey($toma->id)->lockForUpdate()->firstOrFail();
            $this->verificarEnCurso($toma, $usuario);

            $toma->update([
                'estado' => TomaInventario::CANCELADA,
                'motivo_cancelacion' => $motivo,
                'cerrada_at' => now(),
                'cerrada_por' => $usuario?->id,
            ]);

            return $toma;
        });
    }

    /* =========================================================
       Auxiliares
       ========================================================= */

    protected function verificarEnCurso(TomaInventario $toma, ?User $usuario): void
    {
        if (! $toma->estaEnCurso()) {
            throw ValidationException::withMessages(['toma' => "La toma N° {$toma->numero_texto} ya está " . mb_strtolower($toma->estado_nombre) . '.']);
        }

        if ($usuario && ! $usuario->puedeOperarDeposito($toma->deposito_id)) {
            throw ValidationException::withMessages(['toma' => 'No tenés permiso para operar este depósito.']);
        }
    }

    /**
     * Lo que dice el sistema AHORA para el renglón (producto + ubicación + lote exactos).
     *
     * @return array{0: float, 1: ?array}  [cantidad, series en stock (solo productos con serie)]
     */
    protected function teoricoActual(TomaInventario $toma, TomaInventarioItem $item, Producto $producto): array
    {
        // Lote que todavía no existe: el sistema no tiene nada
        if ($item->lote_codigo && ! $item->lote_id) {
            return [0.0, $producto->usa_series ? [] : null];
        }

        $filtro = fn ($q) => $q
            ->where('producto_id', $producto->id)
            ->where('deposito_id', $toma->deposito_id)
            ->when($item->ubicacion_id, fn ($w) => $w->where('ubicacion_id', $item->ubicacion_id), fn ($w) => $w->whereNull('ubicacion_id'))
            ->when($item->lote_id, fn ($w) => $w->where('lote_id', $item->lote_id), fn ($w) => $w->whereNull('lote_id'));

        $cantidad = (float) $filtro(Existencia::query())->sum('cantidad');

        $series = $producto->usa_series
            ? $filtro(Serie::query())->where('estado', Serie::EN_STOCK)->orderBy('numero')->pluck('numero')->all()
            : null;

        return [round($cantidad, 4), $series];
    }

    /** Resumen para la pantalla: cuántos renglones hay, cuántos se contaron, diferencias y su valor. */
    public static function resumen($items): array
    {
        $contados = $items->filter(fn ($i) => $i->contado !== null);
        $diferencias = $contados->filter(fn ($i) => abs((float) $i->diferencia) > StockService::TOLERANCIA
            || ($i->producto?->usa_series && (array_diff($i->series_teoricas ?? [], $i->series_contadas ?? [])
                || array_diff($i->series_contadas ?? [], $i->series_teoricas ?? []))));

        return [
            'total' => $items->count(),
            'contados' => $contados->count(),
            'pendientes' => $items->count() - $contados->count(),
            'avance' => $items->count() ? (int) floor($contados->count() * 100 / $items->count()) : 100,
            'con_diferencia' => $diferencias->count(),
            'faltante' => round($contados->sum(fn ($i) => min(0, (float) $i->valor_diferencia)) * -1, 2),
            'sobrante' => round($contados->sum(fn ($i) => max(0, (float) $i->valor_diferencia)), 2),
        ];
    }

    public static function textoCantidad(float $cantidad, ?Producto $producto): string
    {
        return Formato::cantidad($cantidad) . ' ' . ($producto?->unidad?->abreviatura ?? '');
    }
}
