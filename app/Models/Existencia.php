<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cantidad actual de un producto en un depósito (y ubicación / lote).
 * Solo la modifica App\Services\StockService.
 */
class Existencia extends Model
{
    use BelongsToEmpresa;

    protected $table = 'existencias';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'deposito_id',
        'ubicacion_id',
        'lote_id',
        'clave',
        'cantidad',
        'reservado',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'reservado' => 'decimal:4',
        ];
    }

    /* ---------- Relaciones ---------- */

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

    /* ---------- Helpers ---------- */

    public static function clave(int $productoId, int $depositoId, ?int $ubicacionId = null, ?int $loteId = null): string
    {
        return $productoId . '-' . $depositoId . '-' . ($ubicacionId ?? 0) . '-' . ($loteId ?? 0);
    }

    /** Fila de existencia para la combinación (la crea en cero si no existe). */
    public static function para(int $productoId, int $depositoId, ?int $ubicacionId = null, ?int $loteId = null): self
    {
        return static::firstOrCreate(
            ['clave' => static::clave($productoId, $depositoId, $ubicacionId, $loteId)],
            [
                'producto_id' => $productoId,
                'deposito_id' => $depositoId,
                'ubicacion_id' => $ubicacionId,
                'lote_id' => $loteId,
                'cantidad' => 0,
                'reservado' => 0,
            ]
        );
    }

    public function getDisponibleAttribute(): float
    {
        return (float) $this->cantidad - (float) $this->reservado;
    }
}
