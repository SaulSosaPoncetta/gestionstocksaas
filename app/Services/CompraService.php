<?php

namespace App\Services;

use App\Models\Deposito;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\Numerador;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\RecepcionCompra;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abastecimiento: órdenes de compra, recepciones contra la orden y sugerencia de reposición.
 * El stock lo ingresa StockService (con lote, series y costo promedio).
 */
class CompraService
{
    public const MOTIVO_COMPRA = 'Compra a proveedor';

    public function __construct(protected StockService $stock)
    {
    }

    /* =========================================================
       Órdenes de compra
       ========================================================= */

    /**
     * @param  array  $lineas  [['producto_id', 'cantidad', 'costo_unitario', 'presentacion_id'?], …]
     *                         cantidad y costo vienen en la presentación elegida (o en unidades si no hay)
     */
    public function guardarOrden(?OrdenCompra $orden, array $datos, array $lineas, User $usuario): OrdenCompra
    {
        if ($orden && ! $orden->puedeEditarse()) {
            throw ValidationException::withMessages(['orden' => 'Esta orden ya no se puede modificar.']);
        }

        $proveedor = Proveedor::activos()->find($datos['proveedor_id'] ?? 0);
        if (! $proveedor) {
            throw ValidationException::withMessages(['proveedor_id' => 'Elegí un proveedor activo.']);
        }

        $deposito = Deposito::activos()->find($datos['deposito_id'] ?? 0);
        if (! $deposito || ! $usuario->puedeOperarDeposito($deposito->id)) {
            throw ValidationException::withMessages(['deposito_id' => 'Elegí un depósito que puedas operar.']);
        }

        $renglones = $this->normalizarLineas($lineas);

        return DB::transaction(function () use ($orden, $datos, $renglones, $proveedor, $deposito, $usuario) {
            $orden ??= new OrdenCompra([
                'numero' => Numerador::siguiente('orden_compra'),
                'estado' => OrdenCompra::BORRADOR,
                'user_id' => $usuario->id,
            ]);

            $orden->fill([
                'proveedor_id' => $proveedor->id,
                'deposito_id' => $deposito->id,
                'fecha' => $datos['fecha'] ?? today(),
                'fecha_entrega' => $datos['fecha_entrega'] ?? null,
                'condiciones_pago' => $datos['condiciones_pago'] ?? $proveedor->condiciones_pago,
                'observaciones' => $datos['observaciones'] ?? null,
            ])->save();

            $orden->items()->delete();

            foreach ($renglones as $renglon) {
                $orden->items()->create($renglon);
            }

            $this->recalcularTotales($orden);

            return $orden->fresh(['items']);
        });
    }

    public function enviar(OrdenCompra $orden): OrdenCompra
    {
        if ($orden->estado !== OrdenCompra::BORRADOR) {
            throw ValidationException::withMessages(['orden' => 'Solo se puede marcar como enviada una orden en borrador.']);
        }

        $orden->update(['estado' => OrdenCompra::ENVIADA, 'enviada_at' => now()]);

        return $orden;
    }

    /** Cancela una orden que todavía no recibió nada. */
    public function cancelar(OrdenCompra $orden, ?string $motivo = null): OrdenCompra
    {
        if (! in_array($orden->estado, [OrdenCompra::BORRADOR, OrdenCompra::ENVIADA], true)
            || $orden->recepciones()->where('estado', RecepcionCompra::CONFIRMADA)->exists()) {
            throw ValidationException::withMessages(['orden' => 'Solo se puede cancelar una orden sin recepciones. Si ya llegó algo, cerrala.']);
        }

        $orden->update(['estado' => OrdenCompra::CANCELADA, 'cerrada_at' => now(), 'motivo_cierre' => $motivo]);

        return $orden;
    }

    /** Cierra una orden parcial: lo que falta ya no se espera. */
    public function cerrar(OrdenCompra $orden, ?string $motivo = null): OrdenCompra
    {
        if ($orden->estado !== OrdenCompra::PARCIAL) {
            throw ValidationException::withMessages(['orden' => 'Solo se cierra una orden recibida parcialmente.']);
        }

        $orden->update(['estado' => OrdenCompra::CERRADA, 'cerrada_at' => now(), 'motivo_cierre' => $motivo]);

        return $orden;
    }

    /* =========================================================
       Recepción
       ========================================================= */

    /**
     * @param  array  $lineas  [orden_item_id => ['cantidad', 'costo_unitario', 'ubicacion_id'?, 'lote_codigo'?, 'lote_vencimiento'?, 'series'?], …]
     * @param  array  $datos   remito, factura, fecha, observaciones, actualizar_costos (bool), actualizar_precios (bool)
     */
    public function recibir(OrdenCompra $orden, array $lineas, array $datos, User $usuario): RecepcionCompra
    {
        return DB::transaction(function () use ($orden, $lineas, $datos, $usuario) {
            $orden = OrdenCompra::whereKey($orden->id)->lockForUpdate()->firstOrFail();

            if (! $orden->estaAbierta()) {
                throw ValidationException::withMessages(['orden' => 'Esta orden ya está cerrada o cancelada.']);
            }

            if (! $usuario->puedeOperarDeposito($orden->deposito_id)) {
                throw ValidationException::withMessages(['orden' => 'No tenés permiso para recibir en el depósito de esta orden.']);
            }

            $items = $orden->items()->with('producto')->get()->keyBy('id');
            $errores = [];
            $renglones = [];
            $recibido = [];

            foreach ($lineas as $itemId => $linea) {
                $item = $items->get((int) $itemId);
                $cantidad = (float) ($linea['cantidad'] ?? 0);

                if (! $item || $cantidad <= StockService::TOLERANCIA) {
                    continue;
                }

                if ($cantidad > $item->pendiente + StockService::TOLERANCIA) {
                    $errores[] = "{$item->producto?->nombre}: llegaron " . Formato::cantidad($cantidad)
                        . ' pero quedan pendientes ' . Formato::cantidad($item->pendiente) . '. Si llegó de más, cargá un ingreso aparte.';
                    continue;
                }

                $costo = isset($linea['costo_unitario']) && $linea['costo_unitario'] !== ''
                    ? max(0.0, (float) $linea['costo_unitario'])
                    : (float) $item->costo_unitario;

                $renglones[] = [
                    'producto_id' => $item->producto_id,
                    'cantidad' => $cantidad,
                    'costo_unitario' => $costo,
                    'ubicacion_id' => $linea['ubicacion_id'] ?? null,
                    'lote_codigo' => $linea['lote_codigo'] ?? null,
                    'lote_vencimiento' => $linea['lote_vencimiento'] ?? null,
                    'series' => $linea['series'] ?? null,
                ];

                $recibido[] = ['item' => $item, 'cantidad' => $cantidad, 'costo' => $costo];
            }

            if ($errores) {
                throw ValidationException::withMessages(['lineas' => $errores]);
            }

            if (! $recibido) {
                throw ValidationException::withMessages(['lineas' => 'Indicá la cantidad recibida de al menos un producto.']);
            }

            $recepcion = RecepcionCompra::create([
                'numero' => Numerador::siguiente('recepcion_compra'),
                'orden_compra_id' => $orden->id,
                'proveedor_id' => $orden->proveedor_id,
                'deposito_id' => $orden->deposito_id,
                'remito' => $datos['remito'] ?? null,
                'factura' => $datos['factura'] ?? null,
                'fecha' => $datos['fecha'] ?? today(),
                'estado' => RecepcionCompra::CONFIRMADA,
                'observaciones' => $datos['observaciones'] ?? null,
                'user_id' => $usuario->id,
            ]);

            $motivo = Motivo::firstOrCreate(
                ['tipo' => Movimiento::TIPO_INGRESO, 'nombre' => self::MOTIVO_COMPRA],
                ['pide_costo' => true, 'activo' => true]
            );

            $comprobante = collect([
                filled($datos['remito'] ?? null) ? "Remito {$datos['remito']}" : null,
                "OC N° {$orden->numero_texto}",
            ])->filter()->implode(' · ');

            $movimiento = $this->stock->ingresar($orden->deposito_id, $renglones, [
                'motivo_id' => $motivo->id,
                'recepcion_compra_id' => $recepcion->id,
                'comprobante' => $comprobante,
                'fecha_comprobante' => $datos['fecha'] ?? null,
                'observaciones' => 'Recepción de ' . ($orden->proveedor?->nombre ?? 'proveedor')
                    . (filled($datos['observaciones'] ?? null) ? ". {$datos['observaciones']}" : ''),
            ], $usuario);

            $recepcion->update(['movimiento_id' => $movimiento->id]);

            foreach ($recibido as $r) {
                $item = $r['item'];

                $recepcion->items()->create([
                    'orden_compra_item_id' => $item->id,
                    'producto_id' => $item->producto_id,
                    'cantidad' => $r['cantidad'],
                    'costo_unitario' => $r['costo'],
                ]);

                $item->increment('cantidad_recibida', $r['cantidad']);

                $this->actualizarDatosDeCompra($item->producto, $orden->proveedor_id, $r['costo'], $datos);
            }

            $this->actualizarEstado($orden);

            return $recepcion->fresh(['items']);
        });
    }

    /** Anula una recepción: el stock sale y la orden vuelve a esperar esa mercadería. */
    public function anularRecepcion(RecepcionCompra $recepcion, User $usuario, ?string $motivo = null): RecepcionCompra
    {
        return DB::transaction(function () use ($recepcion, $usuario, $motivo) {
            $recepcion = RecepcionCompra::whereKey($recepcion->id)->lockForUpdate()->firstOrFail();

            if ($recepcion->estaAnulada()) {
                throw ValidationException::withMessages(['recepcion' => 'Esta recepción ya está anulada.']);
            }

            $orden = OrdenCompra::whereKey($recepcion->orden_compra_id)->lockForUpdate()->firstOrFail();

            if ($recepcion->movimiento) {
                $this->stock->anular($recepcion->movimiento, $usuario, "Anulación de la recepción N° {$recepcion->numero_texto}. " . ($motivo ?? ''), [
                    'interno_compra' => true,
                ]);
            }

            foreach ($recepcion->items()->get() as $item) {
                OrdenCompraItem::whereKey($item->orden_compra_item_id)->decrement('cantidad_recibida', (float) $item->cantidad);
            }

            $recepcion->update([
                'estado' => RecepcionCompra::ANULADA,
                'anulada_at' => now(),
                'motivo_anulacion' => $motivo,
            ]);

            // Si estaba cerrada o completa, vuelve a quedar abierta
            if (in_array($orden->estado, [OrdenCompra::RECIBIDA, OrdenCompra::CERRADA], true)) {
                $orden->update(['estado' => OrdenCompra::PARCIAL, 'cerrada_at' => null, 'motivo_cierre' => null]);
            }

            $this->actualizarEstado($orden->fresh());

            return $recepcion->fresh();
        });
    }

    /* =========================================================
       Sugerencia de reposición
       ========================================================= */

    /**
     * Productos que llegaron al punto de reposición (o están bajo el mínimo), descontando lo ya pedido.
     * Cantidad sugerida = hasta el stock máximo; si no tiene máximo, hasta el doble del punto de reposición.
     * Se redondea a la unidad de compra del proveedor y se respeta su mínimo de compra.
     *
     * @param  int[]  $depositoIds
     */
    public function sugerencias(array $depositoIds, ?int $proveedorId = null): Collection
    {
        if (! $depositoIds) {
            return collect();
        }

        $productos = Producto::query()
            ->stockeables()
            ->activos()
            ->where(fn ($q) => $q->whereNotNull('punto_reposicion')->orWhere('stock_minimo', '>', 0))
            ->withSum(['existencias as stock' => fn ($q) => $q->whereIn('deposito_id', $depositoIds)], 'cantidad')
            ->with(['unidad', 'proveedores.proveedor', 'proveedores.presentacion'])
            ->when($proveedorId, fn ($q, $id) => $q->whereHas('proveedores', fn ($p) => $p->where('proveedor_id', $id)))
            ->orderBy('nombre')
            ->get();

        $pedido = OrdenCompraItem::query()
            ->join('ordenes_compra', 'ordenes_compra.id', '=', 'orden_compra_items.orden_compra_id')
            ->whereIn('ordenes_compra.estado', OrdenCompra::ABIERTAS)
            ->whereIn('ordenes_compra.deposito_id', $depositoIds)
            ->whereIn('orden_compra_items.producto_id', $productos->pluck('id'))
            ->groupBy('orden_compra_items.producto_id')
            ->selectRaw('orden_compra_items.producto_id, SUM(orden_compra_items.cantidad - orden_compra_items.cantidad_recibida) AS pendiente')
            ->pluck('pendiente', 'producto_id');

        return $productos->map(function (Producto $producto) use ($pedido, $proveedorId) {
            $stock = (float) $producto->stock;
            $pendiente = max(0.0, (float) ($pedido[$producto->id] ?? 0));
            $disponible = $stock + $pendiente;
            $minimo = (float) $producto->stock_minimo;
            $reposicion = $producto->punto_reposicion !== null ? (float) $producto->punto_reposicion : null;

            $necesita = $reposicion !== null
                ? $disponible <= $reposicion + StockService::TOLERANCIA
                : $disponible < $minimo - StockService::TOLERANCIA;

            if (! $necesita) {
                return null;
            }

            $objetivo = $producto->stock_maximo !== null
                ? (float) $producto->stock_maximo
                : 2 * ($reposicion ?? $minimo);

            $sugerido = max(0.0, $objetivo - $disponible);

            $vinculo = $proveedorId
                ? $producto->proveedores->firstWhere('proveedor_id', $proveedorId)
                : ($producto->proveedores->firstWhere('es_principal', true) ?? $producto->proveedores->first());

            $sugerido = $this->redondearCompra($sugerido, $vinculo);

            if ($sugerido <= StockService::TOLERANCIA) {
                return null;
            }

            return (object) [
                'producto' => $producto,
                'stock' => $stock,
                'pendiente' => $pendiente,
                'minimo' => $minimo,
                'reposicion' => $reposicion,
                'maximo' => $producto->stock_maximo !== null ? (float) $producto->stock_maximo : null,
                'sugerido' => $sugerido,
                'vinculo' => $vinculo,
                'proveedor_id' => $vinculo?->proveedor_id,
                'costo' => (float) ($vinculo?->costo ?? $producto->costo),
                'urgente' => $stock < $minimo,
            ];
        })->filter()->values();
    }

    /**
     * Arma una orden en borrador por proveedor con lo seleccionado.
     *
     * @param  array  $seleccion  [['producto_id', 'proveedor_id', 'cantidad'], …]
     * @return OrdenCompra[]
     */
    public function generarOrdenes(array $seleccion, int $depositoId, User $usuario): array
    {
        $porProveedor = collect($seleccion)
            ->filter(fn ($s) => ! empty($s['proveedor_id']) && (float) ($s['cantidad'] ?? 0) > 0)
            ->groupBy(fn ($s) => (int) $s['proveedor_id']);

        if ($porProveedor->isEmpty()) {
            throw ValidationException::withMessages(['seleccion' => 'Elegí al menos un producto con proveedor y cantidad.']);
        }

        $vinculos = ProductoProveedor::whereIn('producto_id', collect($seleccion)->pluck('producto_id'))->get()
            ->keyBy(fn ($v) => "{$v->producto_id}-{$v->proveedor_id}");
        $productos = Producto::whereIn('id', collect($seleccion)->pluck('producto_id'))->get()->keyBy('id');

        return DB::transaction(function () use ($porProveedor, $vinculos, $productos, $depositoId, $usuario) {
            $ordenes = [];

            foreach ($porProveedor as $proveedorId => $filas) {
                $lineas = $filas->map(function ($fila) use ($vinculos, $productos, $proveedorId) {
                    $vinculo = $vinculos->get("{$fila['producto_id']}-{$proveedorId}");

                    return [
                        'producto_id' => (int) $fila['producto_id'],
                        'cantidad' => (float) $fila['cantidad'],
                        'costo_unitario' => (float) ($vinculo?->costo ?? $productos->get((int) $fila['producto_id'])?->costo ?? 0),
                    ];
                })->values()->all();

                $ordenes[] = $this->guardarOrden(null, [
                    'proveedor_id' => $proveedorId,
                    'deposito_id' => $depositoId,
                    'observaciones' => 'Generada desde la sugerencia de reposición',
                ], $lineas, $usuario);
            }

            return $ordenes;
        });
    }

    /* =========================================================
       Auxiliares
       ========================================================= */

    /** Pasa presentación a unidades y valida los productos. */
    protected function normalizarLineas(array $lineas): array
    {
        $productos = Producto::with('alicuota')->whereIn('id', collect($lineas)->pluck('producto_id')->filter())->get()->keyBy('id');
        $presentaciones = ProductoPresentacion::whereIn('id', collect($lineas)->pluck('presentacion_id')->filter())->get()->keyBy('id');

        $errores = [];
        $renglones = [];

        foreach (array_values($lineas) as $i => $linea) {
            $producto = $productos->get((int) ($linea['producto_id'] ?? 0));
            $cantidad = (float) ($linea['cantidad'] ?? 0);

            if (! $producto || ! $producto->manejaStock()) {
                $errores[] = 'Renglón ' . ($i + 1) . ': el producto no existe o no controla stock.';
                continue;
            }

            if ($cantidad <= 0) {
                $errores[] = "{$producto->nombre}: la cantidad tiene que ser mayor a cero.";
                continue;
            }

            $presentacion = ! empty($linea['presentacion_id']) ? $presentaciones->get((int) $linea['presentacion_id']) : null;

            if (! empty($linea['presentacion_id']) && (! $presentacion || $presentacion->producto_id !== $producto->id)) {
                $errores[] = "{$producto->nombre}: la presentación no corresponde al producto.";
                continue;
            }

            $factor = $presentacion ? (float) $presentacion->cantidad : 1.0;
            $costo = max(0.0, (float) ($linea['costo_unitario'] ?? 0));

            $renglones[] = [
                'producto_id' => $producto->id,
                'presentacion_id' => $presentacion?->id,
                'cantidad_presentacion' => $presentacion ? $cantidad : null,
                'cantidad' => round($cantidad * $factor, 4),
                'cantidad_recibida' => 0,
                'costo_unitario' => $factor > 0 ? round($costo / $factor, 4) : $costo,
                'alicuota_iva' => (float) ($producto->alicuota?->porcentaje ?? 0),
            ];
        }

        if ($errores) {
            throw ValidationException::withMessages(['lineas' => $errores]);
        }

        if (! $renglones) {
            throw ValidationException::withMessages(['lineas' => 'Agregá al menos un producto.']);
        }

        return $renglones;
    }

    protected function recalcularTotales(OrdenCompra $orden): void
    {
        $items = $orden->items()->get();
        $subtotal = $items->sum(fn ($i) => $i->subtotal);
        $iva = $items->sum(fn ($i) => round($i->subtotal * (float) $i->alicuota_iva / 100, 2));

        $orden->update([
            'subtotal' => round($subtotal, 2),
            'iva' => round($iva, 2),
            'total' => round($subtotal + $iva, 2),
        ]);
    }

    protected function actualizarEstado(OrdenCompra $orden): void
    {
        if (! $orden->estaAbierta()) {
            return;
        }

        $items = $orden->items()->get();
        $recibido = (float) $items->sum('cantidad_recibida');
        $completa = $items->every(fn ($i) => $i->pendiente <= StockService::TOLERANCIA);

        $estado = match (true) {
            $completa => OrdenCompra::RECIBIDA,
            $recibido > StockService::TOLERANCIA => OrdenCompra::PARCIAL,
            $orden->enviada_at !== null => OrdenCompra::ENVIADA,
            default => OrdenCompra::BORRADOR,
        };

        $orden->update([
            'estado' => $estado,
            'cerrada_at' => $estado === OrdenCompra::RECIBIDA ? now() : null,
        ]);
    }

    /** Guarda el último costo del proveedor y, si se pidió, el costo de reposición y el precio del producto. */
    protected function actualizarDatosDeCompra(?Producto $producto, int $proveedorId, float $costo, array $datos): void
    {
        if (! $producto) {
            return;
        }

        $vinculo = ProductoProveedor::firstOrNew(['producto_id' => $producto->id, 'proveedor_id' => $proveedorId]);
        $vinculo->es_principal = $vinculo->exists ? $vinculo->es_principal : ! ProductoProveedor::where('producto_id', $producto->id)->exists();
        $vinculo->costo = $costo;
        $vinculo->ultima_compra_at = now();
        $vinculo->save();

        if (! empty($datos['actualizar_costos']) && $costo > 0) {
            $producto->costo = $costo;

            if (! empty($datos['actualizar_precios']) && $producto->margen !== null) {
                $producto->precio_venta = round($costo * (1 + (float) $producto->margen / 100), 4);
            }

            $producto->save();
        }
    }

    protected function redondearCompra(float $cantidad, ?ProductoProveedor $vinculo): float
    {
        if ($cantidad <= 0) {
            return 0.0;
        }

        $factor = $vinculo?->presentacion ? (float) $vinculo->presentacion->cantidad : 1.0;

        if ($factor > 1) {
            $cantidad = ceil($cantidad / $factor - StockService::TOLERANCIA) * $factor;
        } else {
            $cantidad = ceil($cantidad - StockService::TOLERANCIA);
        }

        if ($vinculo?->minimo_compra && $cantidad < (float) $vinculo->minimo_compra) {
            $cantidad = (float) $vinculo->minimo_compra;
        }

        return round($cantidad, 4);
    }
}
