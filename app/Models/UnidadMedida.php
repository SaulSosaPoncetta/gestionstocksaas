<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class UnidadMedida extends Model
{
    use BelongsToEmpresa;

    public const TIPOS = [
        'unidad' => 'Unidad',
        'peso' => 'Peso',
        'volumen' => 'Volumen',
        'longitud' => 'Longitud',
        'superficie' => 'Superficie',
        'otro' => 'Otro',
    ];

    protected $table = 'unidades_medida';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'abreviatura',
        'tipo',
        'unidad_base_id',
        'factor',
        'permite_decimales',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'factor' => 'decimal:6',
            'permite_decimales' => 'boolean',
            'activa' => 'boolean',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'unidad_base_id');
    }

    public function derivadas(): HasMany
    {
        return $this->hasMany(self::class, 'unidad_base_id');
    }

    /* ---------- Conversiones ---------- */

    public function esBase(): bool
    {
        return $this->unidad_base_id === null;
    }

    /** ID de la unidad raíz (ella misma si es base). */
    public function raizId(): int
    {
        return $this->unidad_base_id ?? $this->id;
    }

    /** Cuántas unidades raíz equivale 1 de esta unidad. */
    public function factorARaiz(): float
    {
        return $this->esBase() ? 1.0 : (float) $this->factor;
    }

    public function esCompatibleCon(UnidadMedida $otra): bool
    {
        return $this->raizId() === $otra->raizId();
    }

    /**
     * Convierte una cantidad de esta unidad a otra compatible.
     * Ej: Gramo->convertir(1500, $kilogramo) = 1.5
     */
    public function convertir(float $cantidad, UnidadMedida $destino): float
    {
        if (! $this->esCompatibleCon($destino)) {
            throw new InvalidArgumentException("No se puede convertir {$this->abreviatura} a {$destino->abreviatura}.");
        }

        return $cantidad * $this->factorARaiz() / $destino->factorARaiz();
    }

    /* ---------- Presentación ---------- */

    public function getTipoNombreAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function getFactorTextoAttribute(): string
    {
        return rtrim(rtrim(number_format((float) $this->factor, 6, ',', '.'), '0'), ',');
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }
}
