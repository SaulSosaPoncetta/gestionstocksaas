<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Número de serie de una unidad. Solo lo modifica StockService.
 */
class Serie extends Model
{
    use BelongsToEmpresa;

    public const EN_STOCK = 'en_stock';
    public const EGRESADO = 'egresado';

    protected $table = 'series';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'numero',
        'estado',
        'deposito_id',
        'ubicacion_id',
        'lote_id',
    ];

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

    /** Renglones del kardex en los que se movió esta serie (historial). */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(MovimientoItem::class, 'movimiento_item_serie')->orderBy('movimiento_items.id');
    }

    public function enStock(): bool
    {
        return $this->estado === self::EN_STOCK;
    }

    public function getEstadoNombreAttribute(): string
    {
        if ($this->estado === self::EN_STOCK) {
            return $this->deposito?->es_transito ? 'En tránsito' : 'En stock';
        }

        return 'Egresado';
    }

    /** Normaliza lo que se escanea o pega: un número por línea, coma o punto y coma, sin repetidos. */
    public static function parsear(mixed $valor): array
    {
        $lista = is_array($valor) ? $valor : preg_split('/[\r\n,;]+/', (string) $valor);

        return array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $lista), fn ($n) => $n !== '')));
    }
}
