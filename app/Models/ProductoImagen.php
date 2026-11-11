<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductoImagen extends Model
{
    use BelongsToEmpresa;

    protected $table = 'producto_imagenes';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'ruta',
        'orden',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk('public')->url($this->ruta);
    }
}
