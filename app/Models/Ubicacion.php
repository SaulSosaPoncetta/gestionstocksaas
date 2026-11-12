<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Ubicacion extends Model
{
    use BelongsToEmpresa;

    public const TIPOS = [
        'zona' => 'Zona',
        'pasillo' => 'Pasillo',
        'estante' => 'Estante / rack',
        'posicion' => 'Posición',
    ];

    protected $table = 'ubicaciones';

    protected $fillable = [
        'empresa_id',
        'deposito_id',
        'padre_id',
        'tipo',
        'codigo',
        'nombre',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
        ];
    }

    /* ---------- Relaciones ---------- */

    public function deposito(): BelongsTo
    {
        return $this->belongsTo(Deposito::class);
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'padre_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(self::class, 'padre_id')->orderBy('codigo');
    }

    /* ---------- Árbol ---------- */

    /**
     * Ubicaciones del depósito ordenadas como árbol, con "nivel" para mostrar.
     * Son solo de lectura: no guardar estos modelos.
     */
    public static function arbol(int $depositoId, bool $soloActivas = false): Collection
    {
        $todas = static::query()
            ->where('deposito_id', $depositoId)
            ->when($soloActivas, fn ($q) => $q->where('activa', true))
            ->orderBy('codigo')
            ->get();

        $porPadre = $todas->groupBy(fn ($u) => $u->padre_id ?? 0);
        $resultado = collect();

        $recorrer = function (int $padreId, int $nivel) use (&$recorrer, $porPadre, $resultado) {
            foreach ($porPadre->get($padreId, collect()) as $ubicacion) {
                $ubicacion->nivel = $nivel;
                $resultado->push($ubicacion);
                $recorrer($ubicacion->id, $nivel + 1);
            }
        };

        $recorrer(0, 0);

        return $resultado;
    }

    public function descendientesIds(): array
    {
        $porPadre = static::query()
            ->where('deposito_id', $this->deposito_id)
            ->get(['id', 'padre_id'])
            ->groupBy(fn ($u) => $u->padre_id ?? 0);

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

    /* ---------- Presentación ---------- */

    public function getTipoNombreAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function getEtiquetaAttribute(): string
    {
        return $this->nombre ? "{$this->codigo} · {$this->nombre}" : $this->codigo;
    }

    public function scopeActivas($query)
    {
        return $query->where('activa', true);
    }
}
