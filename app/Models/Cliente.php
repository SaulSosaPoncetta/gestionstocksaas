<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Support\EmpresaActual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    public const DOCUMENTOS = ['CUIT' => 'CUIT', 'CUIL' => 'CUIL', 'DNI' => 'DNI'];

    protected $table = 'clientes';

    protected $fillable = [
        'empresa_id',
        'razon_social',
        'nombre_fantasia',
        'documento_tipo',
        'documento',
        'condicion_iva',
        'contacto',
        'email',
        'telefono',
        'domicilio',
        'localidad',
        'provincia',
        'observaciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }

    public function remitos(): HasMany
    {
        return $this->hasMany(Remito::class);
    }

    /** Si GestiónComercial es dueño del alta de clientes, devuelve su nombre (acá quedan de solo lectura). */
    public static function maestroExterno(): ?string
    {
        $integracion = EmpresaActual::modelo()?->integracion('comercial');

        return $integracion && $integracion->opcion('maestro_clientes') === 'comercial'
            ? $integracion->nombre_sistema
            : null;
    }

    public function getNombreAttribute(): string
    {
        return $this->nombre_fantasia ?: $this->razon_social;
    }

    public function getDireccionCompletaAttribute(): string
    {
        return collect([$this->domicilio, $this->localidad, $this->provincia])->filter()->implode(', ');
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeBuscar($query, ?string $texto)
    {
        $texto = trim((string) $texto);

        return $texto === '' ? $query : $query->where(fn ($q) => $q
            ->where('razon_social', 'like', "%{$texto}%")
            ->orWhere('nombre_fantasia', 'like', "%{$texto}%")
            ->orWhere('documento', 'like', "%{$texto}%"));
    }
}
