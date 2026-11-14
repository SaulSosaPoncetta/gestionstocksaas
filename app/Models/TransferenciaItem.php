<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferenciaItem extends Model
{
    use BelongsToEmpresa;

    protected $table = 'transferencia_items';

    protected $fillable = [
        'empresa_id',
        'transferencia_id',
        'producto_id',
        'lote_id',
        'ubicacion_origen_id',
        'ubicacion_destino_id',
        'cantidad_enviada',
        'cantidad_recibida',
        'series',
        'series_recibidas',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_enviada' => 'decimal:4',
            'cantidad_recibida' => 'decimal:4',
            'series' => 'array',
            'series_recibidas' => 'array',
        ];
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(Transferencia::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function ubicacionOrigen(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_origen_id');
    }

    public function ubicacionDestino(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class, 'ubicacion_destino_id');
    }

    /** Positivo = faltó; 0 = llegó completo. */
    public function getDiferenciaAttribute(): ?float
    {
        return $this->cantidad_recibida === null
            ? null
            : round((float) $this->cantidad_enviada - (float) $this->cantidad_recibida, 4);
    }
}
