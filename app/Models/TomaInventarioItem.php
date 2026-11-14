<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TomaInventarioItem extends Model
{
    use BelongsToEmpresa;

    protected $table = 'toma_inventario_items';

    protected $fillable = [
        'empresa_id',
        'toma_inventario_id',
        'producto_id',
        'ubicacion_id',
        'lote_id',
        'lote_codigo',
        'lote_vencimiento',
        'clave',
        'teorico',
        'contado',
        'series_teoricas',
        'series_contadas',
        'costo_unitario',
        'ajuste',
        'agregado',
        'contado_por',
        'contado_at',
    ];

    protected function casts(): array
    {
        return [
            'teorico' => 'decimal:4',
            'contado' => 'decimal:4',
            'costo_unitario' => 'decimal:4',
            'ajuste' => 'decimal:4',
            'series_teoricas' => 'array',
            'series_contadas' => 'array',
            'lote_vencimiento' => 'date',
            'agregado' => 'boolean',
            'contado_at' => 'datetime',
        ];
    }

    public function toma(): BelongsTo
    {
        return $this->belongsTo(TomaInventario::class, 'toma_inventario_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    public function usuarioConteo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contado_por');
    }

    public static function clave(int $productoId, ?int $ubicacionId, ?int $loteId, ?string $loteCodigo = null): string
    {
        $lote = $loteId ? (string) $loteId : ($loteCodigo ? 'n:' . mb_strtoupper($loteCodigo) : '0');

        return $productoId . '-' . ($ubicacionId ?? 0) . '-' . $lote;
    }

    public function estaContado(): bool
    {
        return $this->contado !== null;
    }

    /** Contado − sistema (null si no se contó). */
    public function getDiferenciaAttribute(): ?float
    {
        return $this->contado === null ? null : round((float) $this->contado - (float) $this->teorico, 4);
    }

    public function getValorDiferenciaAttribute(): ?float
    {
        $diferencia = $this->diferencia;

        return $diferencia === null ? null : round($diferencia * (float) $this->costo_unitario, 2);
    }

    public function getLoteTextoAttribute(): ?string
    {
        if ($this->lote) {
            return $this->lote->codigo;
        }

        return $this->lote_codigo ? $this->lote_codigo . ' (nuevo)' : null;
    }
}
