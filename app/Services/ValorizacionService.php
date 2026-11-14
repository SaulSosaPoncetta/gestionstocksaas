<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Existencia;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\MovimientoItem;
use App\Models\Producto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Valorización del stock.
 *
 * - actual(): stock de hoy a costo promedio y a precio de venta (neto de IVA), con margen potencial.
 * - aFecha(): stock que había al cierre de un día pasado, reconstruido desde el kardex, valorizado
 *   al costo promedio que tenía cada producto ese día.
 * - costoSalidas(): costo de lo que salió en un período (ventas, roturas, vencimientos, faltantes de inventario…).
 *
 * Filtros comunes ($f): categoria_id, marca_id, q (texto), con_stock (bool, por defecto true).
 */
class ValorizacionService
{
    /* =========================================================
       Stock actual
       ========================================================= */

    public function actual(array $depositoIds, array $f = []): array
    {
        $cantidades = Existencia::query()
            ->whereIn('existencias.deposito_id', $depositoIds)
            ->selectRaw('existencias.producto_id, existencias.deposito_id, SUM(existencias.cantidad) AS total')
            ->groupBy('existencias.producto_id', 'existencias.deposito_id')
            ->get();

        return $this->construir($this->agrupar($cantidades), $f, null, true);
    }

    /* =========================================================
       Stock a una fecha pasada
       ========================================================= */

    public function aFecha(Carbon $fecha, array $depositoIds, array $f = []): array
    {
        $fin = $fecha->copy()->endOfDay();

        $cantidades = MovimientoItem::query()
            ->whereIn('movimiento_items.deposito_id', $depositoIds)
            ->where('movimiento_items.fecha', '<=', $fin)
            ->selectRaw('movimiento_items.producto_id, movimiento_items.deposito_id, SUM(movimiento_items.cantidad) AS total')
            ->groupBy('movimiento_items.producto_id', 'movimiento_items.deposito_id')
            ->get();

        $porProducto = $this->agrupar($cantidades);

        // Costo promedio de cada producto al cierre de ese día = el del último renglón del kardex hasta esa fecha
        $ultimos = MovimientoItem::query()
            ->whereIn('movimiento_items.producto_id', array_keys($porProducto))
            ->where('movimiento_items.fecha', '<=', $fin)
            ->selectRaw('MAX(movimiento_items.id) AS id')
            ->groupBy('movimiento_items.producto_id')
            ->pluck('id');

        $costos = MovimientoItem::query()
            ->whereIn('movimiento_items.id', $ultimos)
            ->get(['producto_id', 'costo_promedio', 'costo_unitario'])
            ->mapWithKeys(fn ($i) => [$i->producto_id => (float) ($i->costo_promedio ?? $i->costo_unitario)])
            ->all();

        return $this->construir($porProducto, $f, $costos, false);
    }

    /* =========================================================
       Costo de lo que salió en un período
       ========================================================= */

    /**
     * @return array{motivos: Collection, productos: Collection, totales: array}
     */
    public function costoSalidas(Carbon $desde, Carbon $hasta, array $depositoIds, array $f = []): array
    {
        $filas = MovimientoItem::query()
            ->join('movimientos', 'movimientos.id', '=', 'movimiento_items.movimiento_id')
            ->where('movimientos.estado', Movimiento::ESTADO_CONFIRMADO)
            ->whereIn('movimientos.tipo', [Movimiento::TIPO_EGRESO, Movimiento::TIPO_AJUSTE])
            ->whereBetween('movimiento_items.fecha', [$desde->copy()->startOfDay(), $hasta->copy()->endOfDay()])
            ->whereIn('movimiento_items.deposito_id', $depositoIds)
            ->selectRaw('movimientos.tipo, movimientos.motivo_id, movimiento_items.producto_id,
                SUM(-movimiento_items.cantidad) AS cantidad,
                SUM(-movimiento_items.cantidad * movimiento_items.costo_unitario) AS costo')
            ->groupBy('movimientos.tipo', 'movimientos.motivo_id', 'movimiento_items.producto_id')
            ->get();

        $productos = $this->productos(array_unique($filas->pluck('producto_id')->all()), $f);
        $motivos = Motivo::whereIn('id', $filas->pluck('motivo_id')->filter()->unique())->pluck('nombre', 'id');
        $motivoElegido = $f['motivo_id'] ?? null;

        $porMotivo = [];
        $porProducto = [];

        foreach ($filas as $fila) {
            $producto = $productos->get($fila->producto_id);
            if (! $producto) {
                continue;
            }

            $clave = $fila->motivo_id ? 'm' . $fila->motivo_id : 't' . $fila->tipo;
            $nombre = $fila->motivo_id
                ? ($motivos[$fila->motivo_id] ?? 'Motivo #' . $fila->motivo_id)
                : ($fila->tipo === Movimiento::TIPO_AJUSTE ? 'Ajuste sin motivo' : 'Egreso sin motivo');

            $porMotivo[$clave] ??= ['clave' => $clave, 'motivo_id' => $fila->motivo_id, 'nombre' => $nombre, 'tipo' => $fila->tipo, 'cantidad_productos' => 0, 'costo' => 0.0];
            $porMotivo[$clave]['costo'] += (float) $fila->costo;
            $porMotivo[$clave]['cantidad_productos']++;

            if ($motivoElegido !== null && (string) $motivoElegido !== (string) $fila->motivo_id) {
                continue;
            }

            $p = $porProducto[$producto->id] ??= [
                'producto' => $producto,
                'cantidad' => 0.0,
                'costo' => 0.0,
            ];
            $p['cantidad'] += (float) $fila->cantidad;
            $p['costo'] += (float) $fila->costo;
            $porProducto[$producto->id] = $p;
        }

        // Ingreso estimado a precio de lista actual (el precio real de la venta vive en el sistema de facturación)
        $detalle = collect($porProducto)
            ->filter(fn ($p) => abs($p['cantidad']) > StockService::TOLERANCIA || abs($p['costo']) > 0.005)
            ->map(function ($p) {
                $p['costo_unitario'] = abs($p['cantidad']) > StockService::TOLERANCIA ? $p['costo'] / $p['cantidad'] : 0;
                $p['venta_estimada'] = $p['cantidad'] * (float) $p['producto']->precio_venta;
                $p['margen'] = $p['venta_estimada'] - $p['costo'];
                $p['margen_pct'] = $p['venta_estimada'] > 0 ? $p['margen'] * 100 / $p['venta_estimada'] : null;

                return $p;
            })
            ->sortByDesc('costo')
            ->values();

        $motivosColeccion = collect($porMotivo)->sortByDesc('costo')->values();

        return [
            'motivos' => $motivosColeccion,
            'productos' => $detalle,
            'totales' => [
                'costo' => round($motivosColeccion->sum('costo'), 2),
                'costo_detalle' => round($detalle->sum('costo'), 2),
                'venta_estimada' => round($detalle->sum('venta_estimada'), 2),
                'margen' => round($detalle->sum('margen'), 2),
            ],
        ];
    }

    /* =========================================================
       Armado
       ========================================================= */

    /** [producto_id => [deposito_id => cantidad]] */
    protected function agrupar(Collection $filas): array
    {
        $resultado = [];

        foreach ($filas as $fila) {
            $resultado[$fila->producto_id][$fila->deposito_id] = round((float) $fila->total, 4);
        }

        return $resultado;
    }

    protected function productos(array $ids, array $f): Collection
    {
        $categorias = null;
        if (! empty($f['categoria_id']) && ($categoria = Categoria::find((int) $f['categoria_id']))) {
            $categorias = [$categoria->id, ...$categoria->descendientesIds()];
        }

        return Producto::withTrashed()
            ->with(['categoria', 'marca', 'unidad'])
            ->whereIn('id', $ids)
            ->when($categorias, fn ($q) => $q->whereIn('categoria_id', $categorias))
            ->when(! empty($f['marca_id']), fn ($q) => $q->where('marca_id', (int) $f['marca_id']))
            ->when(filled($f['q'] ?? null), fn ($q) => $q->buscar($f['q']))
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  array|null  $costos  [producto_id => costo]; null = costo de valuación actual del producto
     */
    protected function construir(array $porProducto, array $f, ?array $costos, bool $conPrecio): array
    {
        $productos = $this->productos(array_keys($porProducto), $f);
        $conStock = (bool) ($f['con_stock'] ?? true);

        $filas = collect();
        $porDeposito = [];
        $porCategoria = [];
        $porMarca = [];

        foreach ($productos as $producto) {
            $depositos = $porProducto[$producto->id] ?? [];
            $cantidad = round(array_sum($depositos), 4);

            if ($conStock && abs($cantidad) < StockService::TOLERANCIA) {
                continue;
            }

            $costo = $costos === null ? (float) $producto->costo_valuacion : (float) ($costos[$producto->id] ?? $producto->costo_valuacion);
            $precio = (float) $producto->precio_venta;
            $valorCosto = round($cantidad * $costo, 2);
            $valorVenta = $conPrecio ? round($cantidad * $precio, 2) : null;

            $filas->push([
                'producto' => $producto,
                'cantidad' => $cantidad,
                'depositos' => array_filter($depositos, fn ($c) => abs($c) >= StockService::TOLERANCIA),
                'costo' => $costo,
                'valor_costo' => $valorCosto,
                'precio' => $precio,
                'valor_venta' => $valorVenta,
                'margen' => $conPrecio ? $valorVenta - $valorCosto : null,
                'margen_pct' => $conPrecio && $valorVenta > 0 ? ($valorVenta - $valorCosto) * 100 / $valorVenta : null,
                'negativo' => $cantidad < 0,
                'sin_costo' => $costo <= 0,
            ]);

            foreach ($depositos as $depositoId => $c) {
                $porDeposito[$depositoId] ??= ['id' => $depositoId, 'valor_costo' => 0.0, 'valor_venta' => 0.0, 'productos' => 0];
                $porDeposito[$depositoId]['valor_costo'] += $c * $costo;
                $porDeposito[$depositoId]['valor_venta'] += $conPrecio ? $c * $precio : 0;
                $porDeposito[$depositoId]['productos'] += abs($c) >= StockService::TOLERANCIA ? 1 : 0;
            }

            $this->sumarGrupo($porCategoria, $producto->categoria_id ?? 0, $producto->categoria?->nombre ?? 'Sin categoría', $valorCosto, $valorVenta);
            $this->sumarGrupo($porMarca, $producto->marca_id ?? 0, $producto->marca?->nombre ?? 'Sin marca', $valorCosto, $valorVenta);
        }

        $totalCosto = round($filas->sum('valor_costo'), 2);
        $totalVenta = $conPrecio ? round($filas->sum('valor_venta'), 2) : null;

        $ordenar = fn (array $grupos) => collect($grupos)
            ->map(fn ($g) => $g + ['participacion' => $totalCosto != 0 ? $g['valor_costo'] * 100 / $totalCosto : 0])
            ->sortByDesc('valor_costo')
            ->values();

        return [
            'filas' => $filas->sortByDesc('valor_costo')->values(),
            'por_deposito' => $ordenar($porDeposito),
            'por_categoria' => $ordenar($porCategoria),
            'por_marca' => $ordenar($porMarca),
            'totales' => [
                'productos' => $filas->count(),
                'valor_costo' => $totalCosto,
                'valor_venta' => $totalVenta,
                'margen' => $conPrecio ? round($totalVenta - $totalCosto, 2) : null,
                'margen_pct' => $conPrecio && $totalVenta > 0 ? ($totalVenta - $totalCosto) * 100 / $totalVenta : null,
                'negativos' => $filas->where('negativo', true)->count(),
                'sin_costo' => $filas->where('sin_costo', true)->where('cantidad', '>', 0)->count(),
            ],
        ];
    }

    protected function sumarGrupo(array &$grupos, int $id, string $nombre, float $costo, ?float $venta): void
    {
        $grupos[$id] ??= ['id' => $id, 'nombre' => $nombre, 'valor_costo' => 0.0, 'valor_venta' => 0.0, 'productos' => 0];
        $grupos[$id]['valor_costo'] += $costo;
        $grupos[$id]['valor_venta'] += $venta ?? 0;
        $grupos[$id]['productos']++;
    }
}
