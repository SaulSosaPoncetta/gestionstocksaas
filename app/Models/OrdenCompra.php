<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenCompra extends Model
{
    use BelongsToEmpresa;

    public const BORRADOR = 'borrador';
    public const ENVIADA = 'enviada';
    public const PARCIAL = 'parcial';
    public const RECIBIDA = 'recibida';
    public const CERRADA = 'cerrada';
    public const CANCELADA = 'cancelada';

    public const ESTADOS = [
        self::BORRADOR => 'Borrador',
        self::ENVIADA => 'Enviada al proveedor',
        self::PARCIAL => 'Recibida parcialmente',
        self::RECIBIDA => 'Recibida completa',
        self::CERRADA => 'Cerrada con faltantes',
        self::CANCELADA => 'Cancelada',
    ];

    public const COLORES = [
        self::BORRADOR => 'secondary',
        self::ENVIADA => 'primary',
        self::PARCIAL => 'warning',
        self::RECIBIDA => 'success',
        self::CERRADA => 'dark',
        self::CANCELADA => 'light',
    ];

    /** Estados en los que todavía se espera mercadería. */
    public const ABIERTAS = [self::BORRADOR, self::ENVIADA, self::PARCIAL];

    protected $table = 'ordenes_compra';

    protected $fillable = [
        'empresa_id',
        'numero',
        'proveedor_id',
        'deposito_id',
        'estado',
        'fecha',
        'fecha_entrega',
        'condiciones_pago',
        'observaciones',
        'subtotal',
        'iva',
        'total',
        'user_id',
        'enviada_at',
        'cerrada_at',
        'motivo_cierre',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'fecha' => 'date',
            'fecha_entrega' => 'date',
            'subtotal' => 'decimal:2',
            'iva' => 'decimal:2',
            'total' => 'decimal:2',
            'enviada_at' => 'datetime',
            'cerrada_at' => 'datetime',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function items(): HasMany
    {
        return $this->hasMany(OrdenCompraItem::class)->orderBy('id');
    }

    public function recepciones(): HasMany
    {
        return $this->hasMany(RecepcionCompra::class)->orderBy('id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class)->withTrashed();
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /* ---------- Reglas ---------- */

    public function estaAbierta(): bool
    {
        return in_array($this->estado, self::ABIERTAS, true);
    }

    public function puedeEditarse(): bool
    {
        return in_array($this->estado, [self::BORRADOR, self::ENVIADA], true)
            && ! $this->recepciones()->where('estado', RecepcionCompra::CONFIRMADA)->exists();
    }

    public function puedeRecibirse(): bool
    {
        return $this->estaAbierta() && $this->items()->whereColumn('cantidad_recibida', '<', 'cantidad')->exists();
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

    /** Porcentaje recibido (por cantidad). */
    public function getAvanceAttribute(): int
    {
        $pedido = (float) $this->items->sum('cantidad');

        return $pedido > 0 ? (int) min(100, round((float) $this->items->sum('cantidad_recibida') / $pedido * 100)) : 0;
    }
}
