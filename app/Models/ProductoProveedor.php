<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Producto que se le compra a un proveedor, con su código, costo y unidad de compra.
 */
class ProductoProveedor extends Model
{
    use BelongsToEmpresa;

    protected $table = 'producto_proveedor';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'proveedor_id',
        'codigo_proveedor',
        'presentacion_id',
        'costo',
        'minimo_compra',
        'plazo_entrega_dias',
        'es_principal',
        'ultima_compra_at',
    ];

    protected function casts(): array
    {
        return [
            'costo' => 'decimal:4',
            'minimo_compra' => 'decimal:4',
            'plazo_entrega_dias' => 'integer',
            'es_principal' => 'boolean',
            'ultima_compra_at' => 'datetime',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    /** Lo deja como único proveedor principal del producto. */
    public function marcarPrincipal(): void
    {
        static::where('producto_id', $this->producto_id)->whereKeyNot($this->id)->update(['es_principal' => false]);
        $this->forceFill(['es_principal' => true])->save();
    }
}
