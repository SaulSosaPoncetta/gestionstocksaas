<?php

namespace App\Models;

use App\Services\InicializarEmpresa;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Empresa extends Model
{
    use HasFactory, SoftDeletes;

    public const COSTEO_PROMEDIO = 'promedio_ponderado';
    public const COSTEO_PEPS = 'peps';

    /** Módulos que se venden aparte (los habilita MiGestión según el plan). */
    public const MODULOS = [
        'compras' => 'Abastecimiento: proveedores, órdenes de compra, recepción y reposición',
    ];

    protected $table = 'empresas';

    protected $fillable = [
        'panel_cliente_id',
        'razon_social',
        'nombre_fantasia',
        'cuit',
        'condicion_iva',
        'domicilio',
        'localidad',
        'provincia',
        'telefono',
        'email',
        'logo',
        'metodo_costeo',
        'permite_stock_negativo',
        'usa_lotes',
        'usa_series',
        'dias_aviso_vencimiento',
        'modulos',
        'decimales_cantidad',
        'activa',
        'vence_el',
    ];

    protected function casts(): array
    {
        return [
            'permite_stock_negativo' => 'boolean',
            'usa_lotes' => 'boolean',
            'usa_series' => 'boolean',
            'dias_aviso_vencimiento' => 'integer',
            'modulos' => 'array',
            'decimales_cantidad' => 'integer',
            'activa' => 'boolean',
            'vence_el' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Empresa $empresa) {
            $empresa->uuid ??= (string) Str::uuid();
        });

        // Unidades de medida y categoría inicial
        static::created(function (Empresa $empresa) {
            app(InicializarEmpresa::class)->ejecutar($empresa);
        });
    }

    /* ---------- Relaciones ---------- */

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function integraciones(): HasMany
    {
        return $this->hasMany(Integracion::class);
    }

    /* ---------- Ecosistema ---------- */

    /**
     * Devuelve la integración activa con otro sistema, o null si la empresa
     * no lo usa. Todo el código de integración pregunta por acá antes de actuar.
     */
    public function integracion(string $sistema): ?Integracion
    {
        return $this->integraciones()
            ->withoutGlobalScopes()
            ->where('sistema', $sistema)
            ->where('activa', true)
            ->first();
    }

    public function usaSistema(string $sistema): bool
    {
        return $this->integracion($sistema) !== null;
    }

    /* ---------- Módulos ---------- */

    /** Sin configuración (null) tiene todos; si no, solo los marcados en true. */
    public function tieneModulo(string $modulo): bool
    {
        if ($this->modulos === null) {
            return true;
        }

        return (bool) ($this->modulos[$modulo] ?? false);
    }

    /* ---------- Helpers ---------- */

    public function getNombreAttribute(): string
    {
        return $this->nombre_fantasia ?: $this->razon_social;
    }

    public static function normalizarCuit(string $cuit): string
    {
        $n = preg_replace('/\D/', '', $cuit);

        return strlen($n) === 11
            ? substr($n, 0, 2) . '-' . substr($n, 2, 8) . '-' . substr($n, 10, 1)
            : $cuit;
    }

    public function suscripcionVigente(): bool
    {
        if (! $this->activa) {
            return false;
        }

        return $this->vence_el === null || $this->vence_el->endOfDay()->isFuture();
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }
}
