<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecepcionCompra extends Model
{
    use BelongsToEmpresa;

    public const CONFIRMADA = 'confirmada';
    public const ANULADA = 'anulada';

    protected $table = 'recepciones_compra';

    protected $fillable = [
        'empresa_id',
        'numero',
        'orden_compra_id',
        'proveedor_id',
        'deposito_id',
        'remito',
        'factura',
        'fecha',
        'estado',
        'movimiento_id',
        'observaciones',
        'user_id',
        'anulada_at',
        'motivo_anulacion',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'fecha' => 'date',
            'anulada_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecepcionCompraItem::class)->orderBy('id');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class)->withTrashed();
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(Movimiento::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function estaAnulada(): bool
    {
        return $this->estado === self::ANULADA;
    }

    public function getNumeroTextoAttribute(): string
    {
        return str_pad((string) $this->numero, 8, '0', STR_PAD_LEFT);
    }
}
