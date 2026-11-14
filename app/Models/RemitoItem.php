<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemitoItem extends Model
{
    use BelongsToEmpresa;

    protected $table = 'remito_items';

    protected $fillable = [
        'empresa_id',
        'remito_id',
        'reserva_item_id',
        'producto_id',
        'cantidad',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
        ];
    }

    public function remito(): BelongsTo
    {
        return $this->belongsTo(Remito::class);
    }

    public function reservaItem(): BelongsTo
    {
        return $this->belongsTo(ReservaItem::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }
}
