<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movimiento extends Model
{
    use BelongsToEmpresa;

    public const TIPO_INICIAL = 'inicial';
    public const TIPO_INGRESO = 'ingreso';
    public const TIPO_EGRESO = 'egreso';
    public const TIPO_AJUSTE = 'ajuste';
    public const TIPO_TRANSFERENCIA = 'transferencia';
    public const TIPO_ANULACION = 'anulacion';

    public const TIPOS = [
        self::TIPO_INICIAL => 'Stock inicial',
        self::TIPO_INGRESO => 'Ingreso',
        self::TIPO_EGRESO => 'Egreso',
        self::TIPO_AJUSTE => 'Ajuste',
        self::TIPO_TRANSFERENCIA => 'Transferencia',
        self::TIPO_ANULACION => 'Anulación',
    ];

    public const COLORES = [
        self::TIPO_INICIAL => 'primary',
        self::TIPO_INGRESO => 'success',
        self::TIPO_EGRESO => 'danger',
        self::TIPO_AJUSTE => 'warning',
        self::TIPO_TRANSFERENCIA => 'info',
        self::TIPO_ANULACION => 'secondary',
    ];

    public const ESTADO_CONFIRMADO = 'confirmado';
    public const ESTADO_ANULADO = 'anulado';

    public const ORIGENES = [
        'manual' => 'Manual',
        'sistema' => 'Sistema',
        'comercial' => 'GestiónComercial',
        'mercado' => 'MiGestión Mercado',
        'api' => 'API',
    ];

    protected $table = 'movimientos';

    protected $fillable = [
        'empresa_id',
        'numero',
        'tipo',
        'motivo_id',
        'estado',
        'origen',
        'referencia_externa',
        'deposito_id',
        'deposito_destino_id',
        'comprobante',
        'fecha_comprobante',
        'fecha',
        'observaciones',
        'user_id',
        'anula_a_id',
        'anulado_por_id',
        'anulado_at',
        'anulado_user_id',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'fecha' => 'datetime',
            'fecha_comprobante' => 'date',
            'anulado_at' => 'datetime',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function items(): HasMany
    {
        return $this->hasMany(MovimientoItem::class)->orderBy('id');
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(Motivo::class);
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class)->withTrashed();
    }

    public function depositoDestino(): BelongsTo
    {
        return $this->belongsTo(Deposito::class, 'deposito_destino_id')->withTrashed();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function anulaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'anula_a_id');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'anulado_por_id');
    }

    public function usuarioAnulacion(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_user_id');
    }

    /* ---------- Reglas ---------- */

    public function estaAnulado(): bool
    {
        return $this->estado === self::ESTADO_ANULADO;
    }

    public function puedeAnularse(): bool
    {
        return $this->estado === self::ESTADO_CONFIRMADO && $this->tipo !== self::TIPO_ANULACION;
    }

    /* ---------- Presentación ---------- */

    public function getNumeroTextoAttribute(): string
    {
        return str_pad((string) $this->numero, 8, '0', STR_PAD_LEFT);
    }

    public function getTipoNombreAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function getColorAttribute(): string
    {
        return self::COLORES[$this->tipo] ?? 'secondary';
    }

    public function getOrigenNombreAttribute(): string
    {
        return self::ORIGENES[$this->origen] ?? $this->origen;
    }

    /* ---------- Scopes ---------- */

    /** Movimientos de los depósitos que puede ver el usuario. */
    public function scopeAccesiblesPara($query, User $usuario)
    {
        if ($usuario->operaTodosLosDepositos()) {
            return $query;
        }

        $ids = $usuario->depositos()->pluck('depositos.id');

        return $query->where(function ($q) use ($ids) {
            $q->whereIn('deposito_id', $ids)->orWhereIn('deposito_destino_id', $ids);
        });
    }

    public function puedeVerlo(User $usuario): bool
    {
        return $usuario->operaTodosLosDepositos()
            || $usuario->puedeOperarDeposito($this->deposito_id)
            || ($this->deposito_destino_id && $usuario->puedeOperarDeposito($this->deposito_destino_id));
    }
}
