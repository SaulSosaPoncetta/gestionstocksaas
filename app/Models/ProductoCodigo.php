<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoCodigo extends Model
{
    use BelongsToEmpresa;

    public const TIPOS = [
        'ean13' => 'EAN-13',
        'ean8' => 'EAN-8',
        'upc' => 'UPC',
        'interno' => 'Interno',
        'proveedor' => 'De proveedor',
        'otro' => 'Otro',
    ];

    protected $table = 'producto_codigos';

    protected $fillable = [
        'empresa_id',
        'producto_id',
        'presentacion_id',
        'codigo',
        'tipo',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class)->withTrashed();
    }

    public function presentacion(): BelongsTo
    {
        return $this->belongsTo(ProductoPresentacion::class, 'presentacion_id');
    }

    /** Deduce el tipo a partir del largo del código. */
    public static function tipoSugerido(string $codigo): string
    {
        if (! ctype_digit($codigo)) {
            return 'interno';
        }

        return match (strlen($codigo)) {
            13 => 'ean13',
            8 => 'ean8',
            12 => 'upc',
            default => 'interno',
        };
    }
}
