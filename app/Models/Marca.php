<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Marca extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    protected $table = 'marcas';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }
}
