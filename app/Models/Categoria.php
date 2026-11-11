<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Categoria extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    protected $table = 'categorias';

    protected $fillable = [
        'empresa_id',
        'padre_id',
        'nombre',
        'codigo',
        'descripcion',
        'orden',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'activa' => 'boolean',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'padre_id')->orderBy('orden')->orderBy('nombre');
    }

    /* ---------- Árbol ---------- */

    /**
     * Devuelve todas las categorías ordenadas como árbol, con dos datos extra
     * para mostrar: "nivel" (profundidad) y "ruta" (Bebidas › Gaseosas › Cola).
     * Son solo de lectura: no guardar estos modelos.
     */
    public static function arbol(bool $soloActivas = false): Collection
    {
        $todas = static::query()
            ->when($soloActivas, fn ($q) => $q->where('activa', true))
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        $porPadre = $todas->groupBy(fn ($c) => $c->padre_id ?? 0);
        $resultado = collect();

        $recorrer = function (int $padreId, int $nivel, string $ruta) use (&$recorrer, $porPadre, $resultado) {
            foreach ($porPadre->get($padreId, collect()) as $categoria) {
                $categoria->nivel = $nivel;
                $categoria->ruta = $ruta === '' ? $categoria->nombre : "{$ruta} › {$categoria->nombre}";
                $resultado->push($categoria);
                $recorrer($categoria->id, $nivel + 1, $categoria->ruta);
            }
        };

        $recorrer(0, 0, '');

        return $resultado;
    }

    /**
     * IDs de todas las subcategorías (hijas, nietas, etc.).
     */
    public function descendientesIds(): array
    {
        $porPadre = static::query()->get(['id', 'padre_id'])->groupBy(fn ($c) => $c->padre_id ?? 0);

        $ids = [];
        $pendientes = [$this->id];

        while ($pendientes) {
            $actual = array_pop($pendientes);
            foreach ($porPadre->get($actual, collect()) as $hija) {
                $ids[] = $hija->id;
                $pendientes[] = $hija->id;
            }
        }

        return $ids;
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }
}
