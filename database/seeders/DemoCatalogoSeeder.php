<?php

namespace Database\Seeders;

use App\Models\AlicuotaIva;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Marca;
use App\Models\Motivo;
use App\Models\Producto;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\ProductoService;
use App\Services\StockService;
use App\Support\EmpresaActual;
use Illuminate\Database\Seeder;

/**
 * Depósitos, productos y stock de ejemplo para la empresa demo.
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

            $this->depositos();

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
            $gaseosa = $servicio->guardar(new Producto(['tipo' => Producto::TIPO_SIMPLE]), [
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
            $harina = $servicio->guardar(new Producto(['tipo' => Producto::TIPO_SIMPLE]), [
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
            $remera = $servicio->guardar(new Producto(['tipo' => Producto::TIPO_CON_VARIANTES]), [
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

            // Stock inicial (sin usuario: queda registrado con origen "Sistema")
            $stock = app(StockService::class);
            $principal = Deposito::principal();
            $sucursal = Deposito::where('codigo', 'SUC01')->first();
            $a011 = Ubicacion::where('deposito_id', $principal->id)->where('codigo', 'A-01-1')->value('id');

            $stock->cargarInicial($principal->id, [
                ['producto_id' => $gaseosa->id, 'cantidad' => 60, 'costo_unitario' => 1200, 'ubicacion_id' => $a011],
                ['producto_id' => $harina->id, 'cantidad' => 30, 'costo_unitario' => 650],
                ...$remera->variantes()->get()->map(fn ($v) => [
                    'producto_id' => $v->id, 'cantidad' => 10, 'costo_unitario' => 5000,
                ])->all(),
            ], ['observaciones' => 'Stock inicial de ejemplo']);

            $stock->cargarInicial($sucursal->id, [
                ['producto_id' => $gaseosa->id, 'cantidad' => 12, 'costo_unitario' => 1250],
            ], ['observaciones' => 'Stock inicial de ejemplo']);

            // Una salida en la sucursal para ver el kardex
            $stock->egresar($sucursal->id, [
                ['producto_id' => $gaseosa->id, 'cantidad' => 2],
            ], [
                'motivo_id' => Motivo::where('tipo', 'egreso')->where('nombre', 'Venta')->value('id'),
                'observaciones' => 'Venta mostrador (ejemplo)',
            ]);

            // Una rotura en el depósito principal
            $stock->egresar($principal->id, [
                ['producto_id' => $gaseosa->id, 'cantidad' => 1, 'ubicacion_id' => $a011],
            ], [
                'motivo_id' => Motivo::where('tipo', 'egreso')->where('nombre', 'Rotura')->value('id'),
                'observaciones' => 'Se cayó del estante (ejemplo)',
            ]);
        });
    }

    protected function depositos(): void
    {
        $principal = Deposito::principal();
        $principal->update(['usa_ubicaciones' => true, 'localidad' => 'General Pacheco']);

        // Zona A con 2 estantes de 3 niveles: A, A-01, A-01-1 … A-02-3
        $zona = Ubicacion::create(['deposito_id' => $principal->id, 'tipo' => 'zona', 'codigo' => 'A', 'nombre' => 'Zona A']);
        foreach ([1, 2] as $e) {
            $estante = Ubicacion::create([
                'deposito_id' => $principal->id, 'padre_id' => $zona->id, 'tipo' => 'estante', 'codigo' => sprintf('A-%02d', $e),
            ]);
            foreach ([1, 2, 3] as $n) {
                Ubicacion::create([
                    'deposito_id' => $principal->id, 'padre_id' => $estante->id, 'tipo' => 'posicion', 'codigo' => "{$estante->codigo}-{$n}",
                ]);
            }
        }

        $sucursal = Deposito::create([
            'codigo' => 'SUC01',
            'nombre' => 'Sucursal Centro',
            'tipo' => 'sucursal',
            'localidad' => 'Tigre',
            'activo' => true,
        ]);

        // El operador demo solo trabaja en la sucursal
        $operador = User::where('email', 'operador@demo.com')->first();
        if ($operador) {
            $operador->update(['todos_los_depositos' => false]);
            $operador->depositos()->sync([$sucursal->id]);
        }
    }
}
