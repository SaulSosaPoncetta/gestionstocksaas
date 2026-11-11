<?php

namespace App\Models\Concerns;

use App\Models\Empresa;
use App\Models\Scopes\EmpresaScope;
use App\Support\EmpresaActual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Usar en TODOS los modelos que pertenecen a una empresa
 * (productos, depósitos, movimientos, etc.).
 * - Filtra automáticamente por la empresa actual.
 * - Completa empresa_id al crear.
 */
trait BelongsToEmpresa
{
    protected static function bootBelongsToEmpresa(): void
    {
        static::addGlobalScope(new EmpresaScope);

        static::creating(function (Model $model) {
            if (empty($model->empresa_id) && ($empresaId = EmpresaActual::id())) {
                $model->empresa_id = $empresaId;
            }
        });
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
