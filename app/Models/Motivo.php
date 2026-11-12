<?php

namespace App\Models;

use App\Models\Concerns\BelongsToEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Motivo extends Model
{
    use BelongsToEmpresa;

    public const TIPOS = [
        Movimiento::TIPO_INGRESO => 'Ingresos',
        Movimiento::TIPO_EGRESO => 'Egresos',
        Movimiento::TIPO_AJUSTE => 'Ajustes',
    ];

    /** Motivos con los que arranca cada empresa: [nombre, pide_costo, requiere_observacion] */
    public const INICIALES = [
        Movimiento::TIPO_INGRESO => [
            ['Compra a proveedor', true, false],
            ['Devolución de cliente', false, false],
            ['Producción propia', true, false],
            ['Bonificación / donación recibida', false, false],
            ['Otro ingreso', false, true],
        ],
        Movimiento::TIPO_EGRESO => [
            ['Venta', false, false],
            ['Consumo interno', false, false],
            ['Rotura', false, false],
            ['Vencimiento', false, false],
            ['Robo / faltante', false, true],
            ['Muestra / regalo', false, false],
            ['Devolución a proveedor', false, false],
            ['Otro egreso', false, true],
        ],
        Movimiento::TIPO_AJUSTE => [
            ['Diferencia de inventario', false, false],
            ['Corrección de carga', false, true],
            ['Otro ajuste', false, true],
        ],
    ];

    protected $table = 'motivos';

    protected $fillable = [
        'empresa_id',
        'tipo',
        'nombre',
        'pide_costo',
        'requiere_observacion',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'pide_costo' => 'boolean',
            'requiere_observacion' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(Movimiento::class);
    }

    public static function crearIniciales(): void
    {
        foreach (self::INICIALES as $tipo => $motivos) {
            foreach ($motivos as $orden => [$nombre, $pideCosto, $requiereObservacion]) {
                static::firstOrCreate(
                    ['tipo' => $tipo, 'nombre' => $nombre],
                    [
                        'pide_costo' => $pideCosto,
                        'requiere_observacion' => $requiereObservacion,
                        'activo' => true,
                        'orden' => $orden,
                    ]
                );
            }
        }
    }

    public function getTipoNombreAttribute(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeDeTipo($query, string $tipo)
    {
        return $query->where('tipo', $tipo)->orderBy('orden')->orderBy('nombre');
    }
}
