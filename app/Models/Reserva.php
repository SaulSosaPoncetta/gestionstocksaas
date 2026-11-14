<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reserva extends Model
{
    use BelongsToEmpresa;

    public const ACTIVA = 'activa';
    public const ENTREGADA = 'entregada';
    public const LIBERADA = 'liberada';
    public const VENCIDA = 'vencida';

    public const ESTADOS = [
        self::ACTIVA => 'Activa',
        self::ENTREGADA => 'Entregada',
        self::LIBERADA => 'Liberada',
        self::VENCIDA => 'Vencida',
    ];

    public const COLORES = [
        self::ACTIVA => 'warning',
        self::ENTREGADA => 'success',
        self::LIBERADA => 'secondary',
        self::VENCIDA => 'dark',
    ];

    protected $table = 'reservas';

    protected $fillable = [
        'empresa_id',
        'numero',
        'cliente_id',
        'deposito_id',
        'estado',
        'origen',
        'referencia_externa',
        'vence_at',
        'observaciones',
        'user_id',
        'cerrada_at',
        'motivo_cierre',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'vence_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReservaItem::class)->orderBy('id');
    }

    public function remitos(): HasMany
    {
        return $this->hasMany(Remito::class)->orderBy('id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class)->withTrashed();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function estaActiva(): bool
    {
        return $this->estado === self::ACTIVA;
    }

    public function getNumeroTextoAttribute(): string
    {
        return str_pad((string) $this->numero, 8, '0', STR_PAD_LEFT);
    }

    public function getEstadoNombreAttribute(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    public function getColorAttribute(): string
    {
        return self::COLORES[$this->estado] ?? 'secondary';
    }

    public function getOrigenNombreAttribute(): string
    {
        return Movimiento::ORIGENES[$this->origen] ?? $this->origen;
    }

    public function scopeActivas($query)
    {
        return $query->where('estado', self::ACTIVA);
    }
}
