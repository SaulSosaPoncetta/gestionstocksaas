<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Deposito extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    public const TIPOS = [
        'deposito' => 'Depósito',
        'sucursal' => 'Sucursal / local',
        'consignacion' => 'En consignación',
        'virtual' => 'Virtual',
    ];

    protected $table = 'depositos';

    protected $fillable = [
        'empresa_id',
        'codigo',
        'nombre',
        'tipo',
        'direccion',
        'localidad',
        'provincia',
        'telefono',
        'responsable_id',
        'es_principal',
        'es_transito',
        'usa_ubicaciones',
        'permite_stock_negativo',
        'activo',
        'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'es_principal' => 'boolean',
            'es_transito' => 'boolean',
            'usa_ubicaciones' => 'boolean',
            'permite_stock_negativo' => 'boolean',
            'activo' => 'boolean',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function ubicaciones(): HasMany
    {
        return $this->hasMany(Ubicacion::class)->orderBy('codigo');
    }

    public function usuarios(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'deposito_usuario')->withTimestamps();
    }

    /* ---------- Reglas ---------- */

    /** Configuración efectiva: la del depósito si está definida, si no la de la empresa. */
    public function permiteStockNegativo(): bool
    {
        if ($this->permite_stock_negativo !== null) {
            return $this->permite_stock_negativo;
        }

        return (bool) $this->empresa?->permite_stock_negativo;
    }

    public static function principal(): ?self
    {
        return static::where('es_principal', true)->first()
            ?? static::activos()->orderBy('id')->first();
    }

    /**
     * Depósito virtual donde queda la mercadería mientras viaja entre depósitos.
     * Uno por empresa; se crea la primera vez que se necesita. No aparece en listados ni selectores.
     */
    public static function transito(): self
    {
        return static::withTrashed()->firstOrCreate(
            ['es_transito' => true],
            [
                'codigo' => '_TRANSITO',
                'nombre' => 'En tránsito',
                'tipo' => 'virtual',
                'activo' => true,
                'permite_stock_negativo' => false,
            ]
        );
    }

    /** Deja este depósito como único principal de la empresa. */
    public function marcarComoPrincipal(): void
    {
        static::where('es_principal', true)->whereKeyNot($this->id)->update(['es_principal' => false]);
        $this->forceFill(['es_principal' => true, 'activo' => true])->save();
    }

    /* ---------- Presentación ---------- */

    public function getTipoNombreAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function getEtiquetaAttribute(): string
    {
        return "{$this->codigo} · {$this->nombre}";
    }

    /* ---------- Scopes ---------- */

    /** Depósitos activos y visibles (no incluye "En tránsito"). */
    public function scopeActivos($query)
    {
        return $query->where('activo', true)->where('es_transito', false);
    }

    public function scopeVisibles($query)
    {
        return $query->where('es_transito', false);
    }

    /** Depósitos que puede operar un usuario. */
    public function scopeAccesiblesPara($query, User $usuario)
    {
        if ($usuario->operaTodosLosDepositos()) {
            return $query;
        }

        return $query->whereIn($this->qualifyColumn('id'), $usuario->depositos()->pluck('depositos.id'));
    }
}
