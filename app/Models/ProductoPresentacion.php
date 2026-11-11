<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductoPresentacion extends Model
{
    use BelongsToEmpresa;

    protected $table = 'producto_presentaciones';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'nombre',
        'cantidad',
        'precio_venta',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'precio_venta' => 'decimal:4',
            'activa' => 'boolean',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    /** Código de barras propio de la presentación (ej. el de la caja). */
    public function codigo(): HasOne
    {
        return $this->hasOne(ProductoCodigo::class, 'presentacion_id');
    }

    /** Convierte cantidad de presentaciones a unidades del producto. */
    public function aUnidades(float $cantidad): float
    {
        return $cantidad * (float) $this->cantidad;
    }
}
