<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenCompraItem extends Model
{
    use BelongsToEmpresa;

    protected $table = 'orden_compra_items';

    protected $fillable = [
        'empresa_id',
        'orden_compra_id',
        'producto_id',
        'presentacion_id',
        'cantidad_presentacion',
        'cantidad',
        'cantidad_recibida',
        'costo_unitario',
        'alicuota_iva',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_presentacion' => 'decimal:4',
            'cantidad' => 'decimal:4',
            'cantidad_recibida' => 'decimal:4',
            'costo_unitario' => 'decimal:4',
            'alicuota_iva' => 'decimal:2',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    public function getPendienteAttribute(): float
    {
        return max(0.0, round((float) $this->cantidad - (float) $this->cantidad_recibida, 4));
    }

    public function getSubtotalAttribute(): float
    {
        return round((float) $this->cantidad * (float) $this->costo_unitario, 2);
    }
}
