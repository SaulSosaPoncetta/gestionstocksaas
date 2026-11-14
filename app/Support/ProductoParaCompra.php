<?php

namespace App\Support;

use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\ProductoProveedor;

/**
 * Datos de un producto para cargarlo en una orden de compra (pantalla y búsqueda).
 */
class ProductoParaCompra
{
    public static function datos(Producto $producto, ?int $proveedorId = null, ?ProductoPresentacion $presentacion = null): array
    {
        $vinculo = $proveedorId
            ? ProductoProveedor::where('producto_id', $producto->id)->where('proveedor_id', $proveedorId)->first()
            : null;

        // Unidad de compra sugerida: la escaneada, o la que se le compra a ese proveedor
        $presentacion ??= $vinculo?->presentacion_id
            ? $producto->presentaciones->firstWhere('id', $vinculo->presentacion_id)
            : null;

        return [
            'id' => $producto->id,
            'sku' => $producto->sku,
            'nombre' => $producto->nombre,
            'unidad' => $producto->unidad?->abreviatura,
            'permite_decimales' => (bool) $producto->unidad?->permite_decimales,
            'iva' => (float) ($producto->alicuota?->porcentaje ?? 0),
            // Costo por unidad del producto: el último del proveedor o el de reposición
            'costo' => (float) ($vinculo?->costo ?? $producto->costo),
            'codigo_proveedor' => $vinculo?->codigo_proveedor,
            'presentacion_sugerida' => $presentacion?->id,
            'presentaciones' => $producto->presentaciones
                ->where('activa', true)
                ->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'cantidad' => (float) $p->cantidad])
                ->values()
                ->all(),
        ];
    }
}
