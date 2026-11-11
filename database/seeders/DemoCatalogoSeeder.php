<?php

namespace Database\Seeders;

use App\Models\AlicuotaIva;
use App\Models\Categoria;
use App\Models\Empresa;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Services\ProductoService;
use App\Support\EmpresaActual;
use Illuminate\Database\Seeder;

/**
 * Productos de ejemplo para la empresa demo.
 * Se puede correr de nuevo: si ya hay productos, no hace nada.
 */
class DemoCatalogoSeeder extends Seeder
{
    public function run(Empresa $empresa): void
    {
        EmpresaActual::usar($empresa->id, function () {
            if (Producto::query()->exists()) {
                return;
            }

            $servicio = app(ProductoService::class);

            $iva21 = AlicuotaIva::where('codigo_afip', 5)->value('id');
            $iva105 = AlicuotaIva::where('codigo_afip', 4)->value('id');
            $un = UnidadMedida::where('abreviatura', 'un')->value('id');
            $kg = UnidadMedida::where('abreviatura', 'kg')->value('id');

            // Categorías
            $almacen = Categoria::create(['nombre' => 'Almacén']);
            $bebidas = Categoria::create(['nombre' => 'Bebidas']);
            $gaseosas = Categoria::create(['nombre' => 'Gaseosas', 'padre_id' => $bebidas->id]);
            $indumentaria = Categoria::create(['nombre' => 'Indumentaria']);

            // Marcas
            $marcaBebida = Marca::create(['nombre' => 'Burbujas']);
            $marcaMolino = Marca::create(['nombre' => 'Molino del Sur']);
            $marcaRopa = Marca::create(['nombre' => 'Algodón Norte']);

            // Producto simple con código de barras y presentación con su propio código
            $servicio->guardar(new Producto(['tipo' => Producto::TIPO_SIMPLE]), [
                'nombre' => 'Gaseosa cola 2,25 L',
                'categoria_id' => $gaseosas->id,
                'marca_id' => $marcaBebida->id,
                'unidad_medida_id' => $un,
                'alicuota_iva_id' => $iva21,
                'controla_stock' => true,
                'costo' => 1200,
                'margen' => 40,
                'precio_venta' => 1680,
                'stock_minimo' => 24,
                'punto_reposicion' => 36,
                'stock_maximo' => 120,
                'activo' => true,
                'codigos' => [['codigo' => '7790000000017', 'tipo' => 'ean13']],
                'presentaciones' => [
                    ['nombre' => 'Pack x 6', 'cantidad' => 6, 'precio_venta' => 9600, 'codigo_barras' => '17790000000014'],
                ],
            ]);

            // Producto simple a granel (en kg, con decimales y lotes)
            $servicio->guardar(new Producto(['tipo' => Producto::TIPO_SIMPLE]), [
                'nombre' => 'Harina 000',
                'categoria_id' => $almacen->id,
                'marca_id' => $marcaMolino->id,
                'unidad_medida_id' => $kg,
                'alicuota_iva_id' => $iva105,
                'controla_stock' => true,
                'usa_lotes' => true,
                'costo' => 650,
                'margen' => 35,
                'precio_venta' => 877.5,
                'stock_minimo' => 50,
                'activo' => true,
                'presentaciones' => [
                    ['nombre' => 'Bolsa 25 kg', 'cantidad' => 25, 'precio_venta' => 20000, 'codigo_barras' => null],
                ],
            ]);

            // Producto con variantes: 3 talles x 2 colores = 6 variantes
            $servicio->guardar(new Producto(['tipo' => Producto::TIPO_CON_VARIANTES]), [
                'sku' => 'REM-BAS',
                'nombre' => 'Remera básica',
                'categoria_id' => $indumentaria->id,
                'marca_id' => $marcaRopa->id,
                'unidad_medida_id' => $un,
                'alicuota_iva_id' => $iva21,
                'controla_stock' => true,
                'costo' => 5000,
                'margen' => 80,
                'precio_venta' => 9000,
                'activo' => true,
                'variantes_atributos' => [
                    ['nombre' => 'Talle', 'valores' => 'S, M, L'],
                    ['nombre' => 'Color', 'valores' => 'Blanco, Negro'],
                ],
            ]);

            // Servicio (no lleva stock)
            $servicio->guardar(new Producto(['tipo' => Producto::TIPO_SERVICIO]), [
                'nombre' => 'Flete local',
                'unidad_medida_id' => $un,
                'alicuota_iva_id' => $iva21,
                'costo' => 0,
                'precio_venta' => 5000,
                'activo' => true,
            ]);
        });
    }
}
