<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Producto extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    public const TIPO_SIMPLE = 'simple';
    public const TIPO_CON_VARIANTES = 'con_variantes';
    public const TIPO_VARIANTE = 'variante';
    public const TIPO_SERVICIO = 'servicio';

    public const TIPOS = [
        self::TIPO_SIMPLE => 'Simple',
        self::TIPO_CON_VARIANTES => 'Con variantes',
        self::TIPO_VARIANTE => 'Variante',
        self::TIPO_SERVICIO => 'Servicio',
    ];

    /** Campos que las variantes heredan siempre del producto agrupador. */
    public const CAMPOS_HEREDADOS = [
        'descripcion',
        'categoria_id',
        'marca_id',
        'unidad_medida_id',
        'alicuota_iva_id',
        'controla_stock',
        'usa_lotes',
        'usa_series',
    ];

    protected $table = 'productos';

    protected $fillable = [
        'empresa_id',
        'padre_id',
        'tipo',
        'sku',
        'nombre',
        'descripcion',
        'categoria_id',
        'marca_id',
        'unidad_medida_id',
        'alicuota_iva_id',
        'opciones_variantes',
        'atributos',
        'controla_stock',
        'usa_lotes',
        'usa_series',
        'costo',
        'margen',
        'precio_venta',
        'stock_minimo',
        'punto_reposicion',
        'stock_maximo',
        'peso_kg',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'opciones_variantes' => 'array',
            'atributos' => 'array',
            'controla_stock' => 'boolean',
            'usa_lotes' => 'boolean',
            'usa_series' => 'boolean',
            'costo' => 'decimal:4',
            'costo_promedio' => 'decimal:4',
            'margen' => 'decimal:2',
            'precio_venta' => 'decimal:4',
            'stock_minimo' => 'decimal:4',
            'punto_reposicion' => 'decimal:4',
            'stock_maximo' => 'decimal:4',
            'peso_kg' => 'decimal:3',
            'activo' => 'boolean',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    public function variantes(): HasMany
    {
        return $this->hasMany(self::class, 'padre_id')->orderBy('nombre');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class)->withTrashed();
    }

    public function marca(): BelongsTo
    {
        return $this->belongsTo(Marca::class)->withTrashed();
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(UnidadMedida::class, 'unidad_medida_id');
    }

    public function alicuota(): BelongsTo
    {
        return $this->belongsTo(AlicuotaIva::class, 'alicuota_iva_id');
    }

    public function codigos(): HasMany
    {
        return $this->hasMany(ProductoCodigo::class);
    }

    /** Códigos del producto (sin los de presentaciones). */
    public function codigosPropios(): HasMany
    {
        return $this->hasMany(ProductoCodigo::class)->whereNull('presentacion_id');
    }

    public function presentaciones(): HasMany
    {
        return $this->hasMany(ProductoPresentacion::class)->orderBy('cantidad');
    }

    public function imagenes(): HasMany
    {
        return $this->hasMany(ProductoImagen::class)->orderBy('orden');
    }

    public function existencias(): HasMany
    {
        return $this->hasMany(Existencia::class);
    }

    public function imagenPrincipal(): HasOne
    {
        return $this->hasOne(ProductoImagen::class)->ofMany('orden', 'min');
    }

    /* ---------- Reglas de tipo ---------- */

    public function esAgrupador(): bool
    {
        return $this->tipo === self::TIPO_CON_VARIANTES;
    }

    public function esVariante(): bool
    {
        return $this->tipo === self::TIPO_VARIANTE;
    }

    public function esServicio(): bool
    {
        return $this->tipo === self::TIPO_SERVICIO;
    }

    /** Solo productos simples y variantes con control de stock mueven inventario. */
    public function manejaStock(): bool
    {
        return $this->controla_stock && in_array($this->tipo, [self::TIPO_SIMPLE, self::TIPO_VARIANTE], true);
    }

    /* ---------- Precios ---------- */

    public function getPorcentajeIvaAttribute(): float
    {
        return (float) ($this->alicuota?->porcentaje ?? 0);
    }

    /** Costo para valorizar el stock: el promedio ponderado, o el de reposición si todavía no hubo ingresos. */
    public function getCostoValuacionAttribute(): float
    {
        return (float) $this->costo_promedio > 0 ? (float) $this->costo_promedio : (float) $this->costo;
    }

    public function getPrecioFinalAttribute(): float
    {
        return round((float) $this->precio_venta * (1 + $this->porcentaje_iva / 100), 2);
    }

    /* ---------- Presentación ---------- */

    public function getTipoNombreAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function getAtributosTextoAttribute(): string
    {
        return implode(' / ', array_values($this->atributos ?? []));
    }

    public function getImagenUrlAttribute(): ?string
    {
        $imagen = $this->relationLoaded('imagenPrincipal') ? $this->imagenPrincipal : $this->imagenes->first();

        return $imagen ? Storage::disk('public')->url($imagen->ruta) : null;
    }

    /* ---------- Scopes ---------- */

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    /** Productos que mueven stock (simples y variantes). */
    public function scopeStockeables($query)
    {
        return $query->where('controla_stock', true)
            ->whereIn('tipo', [self::TIPO_SIMPLE, self::TIPO_VARIANTE]);
    }

    public function scopeBuscar($query, ?string $texto)
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return $query;
        }

        return $query->where(function ($q) use ($texto) {
            $q->where('nombre', 'like', "%{$texto}%")
                ->orWhere('sku', 'like', "%{$texto}%")
                ->orWhereHas('codigos', fn ($c) => $c->where('codigo', $texto));
        });
    }
}
