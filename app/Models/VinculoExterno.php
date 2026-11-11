<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class VinculoExterno extends Model
{
    use BelongsToEmpresa;

    protected $table = 'vinculos_externos';

    protected $fillable = [
        'empresa_id',
        'vinculable_type',
        'vinculable_id',
        'sistema',
        'id_externo',
        'datos',
        'sincronizado_at',
    ];

    protected function casts(): array
    {
        return [
            'datos' => 'array',
            'sincronizado_at' => 'datetime',
        ];
    }

    public function vinculable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Busca el registro local que corresponde a un ID de otro sistema.
     * Ej: VinculoExterno::local('comercial', Producto::class, '1532')
     */
    public static function local(string $sistema, string $modelo, string $idExterno): ?Model
    {
        return static::query()
            ->where('sistema', $sistema)
            ->where('vinculable_type', (new $modelo)->getMorphClass())
            ->where('id_externo', $idExterno)
            ->first()
            ?->vinculable;
    }

    /**
     * Crea o actualiza el vínculo de un registro local con su ID externo.
     */
    public static function vincular(Model $local, string $sistema, string $idExterno, array $datos = []): self
    {
        return static::updateOrCreate(
            [
                'vinculable_type' => $local->getMorphClass(),
                'vinculable_id' => $local->getKey(),
                'sistema' => $sistema,
            ],
            [
                'id_externo' => $idExterno,
                'datos' => $datos ?: null,
                'sincronizado_at' => now(),
            ]
        );
    }
}
