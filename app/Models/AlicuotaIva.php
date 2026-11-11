<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Alícuotas de IVA según códigos de ARCA (ex AFIP).
 * Son globales (iguales para todas las empresas) y se cargan en la migración.
 */
class AlicuotaIva extends Model
{
    protected $table = 'alicuotas_iva';

    public const CODIGO_21 = 5;

    protected $fillable = [
        'codigo_afip',
        'nombre',
        'porcentaje',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'codigo_afip' => 'integer',
            'porcentaje' => 'decimal:2',
            'activa' => 'boolean',
        ];
    }

    public static function predeterminada(): ?self
    {
        return static::where('codigo_afip', self::CODIGO_21)->first();
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true)->orderByDesc('porcentaje')->orderBy('codigo_afip');
    }
}
