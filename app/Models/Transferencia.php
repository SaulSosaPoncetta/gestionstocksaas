<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transferencia extends Model
{
    use BelongsToEmpresa;

    public const EN_TRANSITO = 'en_transito';
    public const RECIBIDA = 'recibida';
    public const CON_DIFERENCIAS = 'con_diferencias';
    public const ANULADA = 'anulada';

    public const ESTADOS = [
        self::EN_TRANSITO => 'En tránsito',
        self::RECIBIDA => 'Recibida',
        self::CON_DIFERENCIAS => 'Recibida con diferencias',
        self::ANULADA => 'Anulada',
    ];

    public const COLORES = [
        self::EN_TRANSITO => 'warning',
        self::RECIBIDA => 'success',
        self::CON_DIFERENCIAS => 'danger',
        self::ANULADA => 'secondary',
    ];

    public const DIFERENCIAS = [
        'faltante' => 'Registrar como faltante (egreso)',
        'devolver' => 'Devolver al depósito de origen',
    ];

    protected $table = 'transferencias';

    protected $fillable = [
        'empresa_id',
        'numero',
        'estado',
        'deposito_origen_id',
        'deposito_destino_id',
        'transportista',
        'observaciones',
        'enviada_at',
        'enviada_por_id',
        'recibida_at',
        'recibida_por_id',
        'destino_diferencias',
        'observaciones_recepcion',
        'anulada_at',
        'anulada_por_id',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'enviada_at' => 'datetime',
            'recibida_at' => 'datetime',
            'anulada_at' => 'datetime',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function items(): HasMany
    {
        return $this->hasMany(TransferenciaItem::class)->orderBy('id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class)->orderBy('id');
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_origen_id')->withTrashed();
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_destino_id')->withTrashed();
    }

    public function enviadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviada_por_id');
    }

    public function recibidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibida_por_id');
    }

    public function anuladaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulada_por_id');
    }

    /* ---------- Reglas ---------- */

    public function enTransito(): bool
    {
        return $this->estado === self::EN_TRANSITO;
    }

    public function puedeVerla(User $usuario): bool
    {
        return $usuario->puedeOperarDeposito($this->deposito_origen_id)
            || $usuario->puedeOperarDeposito($this->deposito_destino_id);
    }

    public function puedeRecibirla(User $usuario): bool
    {
        return $this->enTransito() && $usuario->puedeOperarDeposito($this->deposito_destino_id);
    }

    public function puedeAnularla(User $usuario): bool
    {
        return $this->enTransito()
            && $usuario->tieneRol('admin', 'encargado')
            && $usuario->puedeOperarDeposito($this->deposito_origen_id);
    }

    /* ---------- Presentación ---------- */

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

    /* ---------- Scopes ---------- */

    public function scopeAccesiblesPara($query, User $usuario)
    {
        if ($usuario->operaTodosLosDepositos()) {
            return $query;
        }

        $ids = $usuario->depositos()->pluck('depositos.id');

        return $query->where(fn ($q) => $q->whereIn('deposito_origen_id', $ids)->orWhereIn('deposito_destino_id', $ids));
    }

    /** En tránsito hacia depósitos que el usuario puede recibir. */
    public function scopePendientesPara($query, User $usuario)
    {
        $query->where('estado', self::EN_TRANSITO);

        if ($usuario->operaTodosLosDepositos()) {
            return $query;
        }

        return $query->whereIn('deposito_destino_id', $usuario->depositos()->pluck('depositos.id'));
    }
}
