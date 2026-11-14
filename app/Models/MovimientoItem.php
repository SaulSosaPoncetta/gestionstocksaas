<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Renglón de un movimiento. El conjunto de renglones de un producto es su kardex.
 */
class MovimientoItem extends Model
{
    use BelongsToEmpresa;

    protected $table = 'movimiento_items';

    protected $fillable = [
        'empresa_id',
        'movimiento_id',
        'producto_id',
        'deposito_id',
        'ubicacion_id',
        'lote_id',
        'presentacion_id',
        'cantidad_presentacion',
        'cantidad',
        'cantidad_contada',
        'saldo',
        'costo_unitario',
        'fecha',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_presentacion' => 'decimal:4',
            'cantidad' => 'decimal:4',
            'cantidad_contada' => 'decimal:4',
            'saldo' => 'decimal:4',
            'costo_unitario' => 'decimal:4',
            'fecha' => 'datetime',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class)->withTrashed();
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Serie::class, 'movimiento_item_serie')->orderBy('series.numero');
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    /* ---------- Presentación ---------- */

    public function getEntradaAttribute(): ?float
    {
        return (float) $this->cantidad > 0 ? (float) $this->cantidad : null;
    }

    public function getSalidaAttribute(): ?float
    {
        return (float) $this->cantidad < 0 ? abs((float) $this->cantidad) : null;
    }

    public function getSubtotalAttribute(): float
    {
        return abs((float) $this->cantidad) * (float) $this->costo_unitario;
    }
}
