<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use App\Support\EmpresaActual;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Proveedor extends Model
{
    use BelongsToEmpresa, SoftDeletes;

    protected $table = 'proveedores';

    protected $fillable = [
        'empresa_id',
        'razon_social',
        'nombre_fantasia',
        'cuit',
        'condicion_iva',
        'contacto',
        'email',
        'telefono',
        'domicilio',
        'localidad',
        'provincia',
        'condiciones_pago',
        'plazo_entrega_dias',
        'observaciones',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'plazo_entrega_dias' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ProductoProveedor::class);
    }

    public function ordenes(): HasMany
    {
        return $this->hasMany(OrdenCompra::class);
    }

    /**
     * Si otro sistema del ecosistema es dueño del alta de proveedores, devuelve su nombre
     * (en ese caso en Stock no se crean ni editan: llegan sincronizados por la integración).
     */
    public static function maestroExterno(): ?string
    {
        $integracion = EmpresaActual::modelo()?->integracion('comercial');

        return $integracion && $integracion->opcion('maestro_proveedores') === 'comercial'
            ? $integracion->nombre_sistema
            : null;
    }

    public function getNombreAttribute(): string
    {
        return $this->nombre_fantasia ?: $this->razon_social;
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
            ->orWhere('cuit', 'like', "%{$texto}%"));
    }
}
