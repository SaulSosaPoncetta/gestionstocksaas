<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Toma de inventario físico: se cuenta la mercadería de un depósito (o de una parte)
 * y al cerrar se ajustan solas las diferencias contra el sistema.
 */
class TomaInventario extends Model
{
    use BelongsToEmpresa;

    public const EN_CURSO = 'en_curso';
    public const CERRADA = 'cerrada';
    public const CANCELADA = 'cancelada';

    public const ESTADOS = [
        self::EN_CURSO => 'En curso',
        self::CERRADA => 'Cerrada',
        self::CANCELADA => 'Cancelada',
    ];

    public const COLORES = [
        self::EN_CURSO => 'warning',
        self::CERRADA => 'success',
        self::CANCELADA => 'secondary',
    ];

    protected $table = 'tomas_inventario';

    protected $fillable = [
        'empresa_id',
        'numero',
        'deposito_id',
        'ubicacion_id',
        'categoria_id',
        'descripcion',
        'estado',
        'congela',
        'a_ciegas',
        'incluir_sin_stock',
        'observaciones',
        'user_id',
        'no_contados',
        'movimiento_id',
        'valor_faltante',
        'valor_sobrante',
        'lineas_con_diferencia',
        'cerrada_at',
        'cerrada_por',
        'motivo_cancelacion',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'congela' => 'boolean',
            'a_ciegas' => 'boolean',
            'incluir_sin_stock' => 'boolean',
            'valor_faltante' => 'decimal:2',
            'valor_sobrante' => 'decimal:2',
            'lineas_con_diferencia' => 'integer',
            'cerrada_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TomaInventarioItem::class);
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class)->withTrashed();
    }

    public function ubicacion(): BelongsTo
    {
        return $this->belongsTo(Ubicacion::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function usuarioCierre(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    public function estaEnCurso(): bool
    {
        return $this->estado === self::EN_CURSO;
    }

    /** El operador no ve lo que dice el sistema si la toma es "a ciegas" (admin y encargado siempre lo ven). */
    public function muestraTeoricoA(User $usuario): bool
    {
        return ! $this->a_ciegas || $usuario->tieneRol('admin', 'encargado') || ! $this->estaEnCurso();
    }

    public function getNumeroTextoAttribute(): string
    {
        return str_pad((string) $this->numero, 6, '0', STR_PAD_LEFT);
    }

    public function getEstadoNombreAttribute(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function getColorAttribute(): string
    {
        return self::COLORES[$this->estado] ?? 'secondary';
    }

    public function getAlcanceAttribute(): string
    {
        $partes = array_filter([
            $this->ubicacion ? 'Ubicación ' . $this->ubicacion->codigo : null,
            $this->categoria ? 'Categoría ' . $this->categoria->nombre : null,
        ]);

        return $partes ? implode(' · ', $partes) : 'Todo el depósito';
    }

    public function scopeEnCurso($query)
    {
        return $query->where('estado', self::EN_CURSO);
    }
}
