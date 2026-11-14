<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservaItem extends Model
{
    use BelongsToEmpresa;

    protected $table = 'reserva_items';

    protected $fillable = [
        'empresa_id',
        'reserva_id',
        'producto_id',
        'lote_id',
        'cantidad',
        'cantidad_entregada',
        'cantidad_liberada',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'cantidad_entregada' => 'decimal:4',
            'cantidad_liberada' => 'decimal:4',
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** Lo que sigue reservado: ni entregado ni liberado. */
    public function getPendienteAttribute(): float
    {
        return max(0.0, round((float) $this->cantidad - (float) $this->cantidad_entregada - (float) $this->cantidad_liberada, 4));
    }
}
