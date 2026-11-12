<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Support\EmpresaActual;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Numeración correlativa por empresa, sin huecos ni repetidos.
 * Llamar siempre dentro de una transacción: la fila queda bloqueada hasta el commit.
 */
class Numerador extends Model
{
    use BelongsToEmpresa;

    protected $table = 'numeradores';

    protected $fillable = [
        'empresa_id',
        'clave',
        'ultimo',
    ];

    protected function casts(): array
    {
        return [
            'ultimo' => 'integer',
        ];
    }

    public static function siguiente(string $clave): int
    {
        $empresaId = EmpresaActual::id();

        if ($empresaId === null) {
            throw new RuntimeException('No hay una empresa seleccionada para numerar.');
        }

        $ahora = now();

        static::query()->insertOrIgnore([
            'empresa_id' => $empresaId,
            'clave' => $clave,
            'ultimo' => 0,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $fila = static::query()->where('clave', $clave)->lockForUpdate()->firstOrFail();
        $fila->ultimo = $fila->ultimo + 1;
        $fila->save();

        return $fila->ultimo;
    }
}
