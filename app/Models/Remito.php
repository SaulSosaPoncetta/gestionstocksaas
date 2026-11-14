<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Remito de entrega (constancia de salida de mercadería; no es comprobante fiscal).
 */
class Remito extends Model
{
    use BelongsToEmpresa;

    public const CONFIRMADO = 'confirmado';
    public const ANULADO = 'anulado';

    protected $table = 'remitos';

    protected $fillable = [
        'empresa_id',
        'numero',
        'cliente_id',
        'deposito_id',
        'reserva_id',
        'estado',
        'origen',
        'referencia_externa',
        'fecha',
        'direccion_entrega',
        'transportista',
        'comprobante_venta',
        'observaciones',
        'movimiento_id',
        'user_id',
        'anulado_at',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'fecha' => 'date',
            'anulado_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RemitoItem::class)->orderBy('id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class)->withTrashed();
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class)->withTrashed();
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class);
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function estaAnulado(): bool
    {
        return $this->estado === self::ANULADO;
    }

    public function getNumeroTextoAttribute(): string
    {
        return str_pad((string) $this->numero, 8, '0', STR_PAD_LEFT);
    }

    public function getOrigenNombreAttribute(): string
    {
        return Movimiento::ORIGENES[$this->origen] ?? $this->origen;
    }
}
