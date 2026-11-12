<?php

namespace App\Services;

use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Motivo;
use App\Models\Empresa;
use App\Models\UnidadMedida;
use App\Support\EmpresaActual;
use Illuminate\Support\Facades\DB;

/**
 * Carga los datos iniciales de una empresa nueva.
 * Se ejecuta automáticamente al crear la empresa y se puede volver a correr sin duplicar.
 */
class InicializarEmpresa
{
    public function ejecutar(Empresa $empresa): void
    {
        EmpresaActual::usar($empresa->id, function () {
            DB::transaction(function () {
                if (! UnidadMedida::query()->exists()) {
                    $this->unidades();
                }

                if (! Categoria::query()->exists()) {
                    Categoria::create(['nombre' => 'General', 'activa' => true]);
                }

                if (! Motivo::query()->exists()) {
                    Motivo::crearIniciales();
                }

                if (! Deposito::withTrashed()->exists()) {
                    Deposito::create([
                        'codigo' => 'DEP01',
                        'nombre' => 'Depósito principal',
                        'tipo' => 'deposito',
                        'es_principal' => true,
                        'activo' => true,
                    ]);
                }
            });
        });
    }

    protected function unidades(): void
    {
        $unidad = $this->crear('Unidad', 'un', 'unidad', decimales: false);
        $this->crear('Docena', 'doc', 'unidad', base: $unidad, factor: 12, decimales: false);

        $kilo = $this->crear('Kilogramo', 'kg', 'peso');
        $this->crear('Gramo', 'g', 'peso', base: $kilo, factor: 0.001);

        $litro = $this->crear('Litro', 'l', 'volumen');
        $this->crear('Mililitro', 'ml', 'volumen', base: $litro, factor: 0.001);

        $metro = $this->crear('Metro', 'm', 'longitud');
        $this->crear('Centímetro', 'cm', 'longitud', base: $metro, factor: 0.01);

        $this->crear('Metro cuadrado', 'm2', 'superficie');
    }

    protected function crear(
        string $nombre,
        string $abreviatura,
        string $tipo,
        ?UnidadMedida $base = null,
        float $factor = 1,
        bool $decimales = true
    ): UnidadMedida {
        return UnidadMedida::create([
            'nombre' => $nombre,
            'abreviatura' => $abreviatura,
            'tipo' => $tipo,
            'unidad_base_id' => $base?->id,
            'factor' => $base ? $factor : 1,
            'permite_decimales' => $decimales,
            'activa' => true,
        ]);
    }
}
