<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Motivo;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\StockService;
use App\Services\ValorizacionService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * php artisan test --filter=ValorizacionTest
 */
class ValorizacionTest extends TestCase
{
    use RefreshDatabase;

    protected ValorizacionService $valorizacion;
    protected StockService $stock;
    protected User $admin;
    protected Deposito $deposito;
    protected Deposito $sucursal;

    protected function setUp(): void
    {
        parent::setUp();

        EmpresaActual::seleccionar(null);

        $empresa = Empresa::create(['razon_social' => 'Prueba S.A.', 'cuit' => '30-71234567-1']);
        $this->admin = User::create([
            'empresa_id' => $empresa->id, 'name' => 'Admin', 'email' => 'admin@prueba.com',
            'password' => 'secreto123', 'rol' => User::ROL_ADMIN, 'activo' => true,
        ]);
        $this->actingAs($this->admin);

        $this->valorizacion = app(ValorizacionService::class);
        $this->stock = app(StockService::class);
        $this->deposito = Deposito::principal();
        $this->sucursal = Deposito::create(['codigo' => 'SUC01', 'nombre' => 'Sucursal', 'activo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function producto(array $datos = []): Producto
    {
        return Producto::create($datos + [
            'tipo' => Producto::TIPO_SIMPLE,
            'sku' => 'P' . uniqid(),
            'nombre' => 'Producto ' . uniqid(),
            'unidad_medida_id' => UnidadMedida::where('abreviatura', 'un')->value('id'),
            'alicuota_iva_id' => AlicuotaIva::where('codigo_afip', 5)->value('id'),
            'controla_stock' => true,
            'costo' => 100,
            'precio_venta' => 150,
            'activo' => true,
        ]);
    }

    protected function depositos(): array
    {
        return [$this->deposito->id, $this->sucursal->id];
    }

    public function test_valoriza_el_stock_actual_a_costo_y_a_venta(): void
    {
        $a = $this->producto(['precio_venta' => 150]);
        $b = $this->producto(['precio_venta' => 80]);

        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $a->id, 'cantidad' => 10, 'costo_unitario' => 100],
            ['producto_id' => $b->id, 'cantidad' => 4, 'costo_unitario' => 50],
        ]);
        $this->stock->cargarInicial($this->sucursal->id, [['producto_id' => $a->id, 'cantidad' => 2, 'costo_unitario' => 100]]);

        $datos = $this->valorizacion->actual($this->depositos());

        $this->assertSame(2, $datos['totales']['productos']);
        $this->assertEquals(12 * 100 + 4 * 50, $datos['totales']['valor_costo']);
        $this->assertEquals(12 * 150 + 4 * 80, $datos['totales']['valor_venta']);
        $this->assertEquals(2120 - 1400, $datos['totales']['margen']);

        $sucursal = $datos['por_deposito']->firstWhere('id', $this->sucursal->id);
        $this->assertEquals(200, round($sucursal['valor_costo'], 2));
    }

    public function test_usa_el_costo_promedio_ponderado(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 10, 'costo_unitario' => 100]]);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 10, 'costo_unitario' => 200]]);

        $fila = $this->valorizacion->actual($this->depositos())['filas']->first();

        $this->assertEquals(150, $fila['costo']);
        $this->assertEquals(3000, $fila['valor_costo']);
    }

    public function test_valoriza_a_una_fecha_pasada_desde_el_kardex(): void
    {
        $p = $this->producto();

        Carbon::setTestNow(now()->subDays(5));
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 10, 'costo_unitario' => 100]]);

        Carbon::setTestNow(now()->addDays(3)); // hace 2 días
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 10, 'costo_unitario' => 200]]);

        Carbon::setTestNow(); // hoy
        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 5]]);

        $haceTres = $this->valorizacion->aFecha(today()->subDays(3), $this->depositos())['filas']->first();
        $this->assertEquals(10, $haceTres['cantidad']);
        $this->assertEquals(1000, $haceTres['valor_costo']);

        $ayer = $this->valorizacion->aFecha(today()->subDay(), $this->depositos())['filas']->first();
        $this->assertEquals(20, $ayer['cantidad']);
        $this->assertEquals(3000, $ayer['valor_costo']);

        $hoy = $this->valorizacion->actual($this->depositos())['filas']->first();
        $this->assertEquals(15, $hoy['cantidad']);
        $this->assertEquals(2250, $hoy['valor_costo']);

        $antes = $this->valorizacion->aFecha(today()->subDays(10), $this->depositos());
        $this->assertSame(0, $antes['totales']['productos']);
    }

    public function test_costo_de_salidas_por_motivo_sin_lo_anulado(): void
    {
        $p = $this->producto(['precio_venta' => 300]);
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 20, 'costo_unitario' => 100]]);

        $venta = Motivo::where('tipo', 'egreso')->where('nombre', 'Venta')->value('id');
        $rotura = Motivo::where('tipo', 'egreso')->where('nombre', 'Rotura')->value('id');

        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 5]], ['motivo_id' => $venta]);
        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1]], ['motivo_id' => $rotura]);
        $anulada = $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3]], ['motivo_id' => $venta]);
        $this->stock->anular($anulada, $this->admin, 'Error de carga');

        $datos = $this->valorizacion->costoSalidas(today(), today(), $this->depositos(), ['motivo_id' => $venta]);

        $this->assertEquals(600, $datos['totales']['costo']); // 5 vendidas + 1 rota, a $100
        $this->assertEquals(500, round($datos['motivos']->firstWhere('motivo_id', $venta)['costo'], 2));
        $this->assertEquals(100, round($datos['motivos']->firstWhere('motivo_id', $rotura)['costo'], 2));

        $detalle = $datos['productos']->first();
        $this->assertEquals(5, $detalle['cantidad']);
        $this->assertEquals(1500, $detalle['venta_estimada']);
        $this->assertEquals(1000, $detalle['margen']);
    }

    public function test_filtra_por_categoria_con_subcategorias(): void
    {
        $bebidas = Categoria::create(['nombre' => 'Bebidas', 'activa' => true]);
        $gaseosas = Categoria::create(['nombre' => 'Gaseosas', 'padre_id' => $bebidas->id, 'activa' => true]);
        $limpieza = Categoria::create(['nombre' => 'Limpieza', 'activa' => true]);

        $a = $this->producto(['categoria_id' => $gaseosas->id]);
        $b = $this->producto(['categoria_id' => $limpieza->id]);
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $a->id, 'cantidad' => 1, 'costo_unitario' => 100],
            ['producto_id' => $b->id, 'cantidad' => 1, 'costo_unitario' => 100],
        ]);

        $datos = $this->valorizacion->actual($this->depositos(), ['categoria_id' => $bebidas->id]);

        $this->assertSame(1, $datos['totales']['productos']);
        $this->assertSame($a->id, $datos['filas']->first()['producto']->id);
    }

    public function test_la_pantalla_y_el_csv_responden(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3, 'costo_unitario' => 100]]);

        $this->get(route('inventario.valorizacion.index'))->assertOk()->assertSee($p->nombre);
        $this->get(route('inventario.valorizacion.historica', ['fecha' => today()->toDateString()]))->assertOk();
        $this->get(route('inventario.valorizacion.salidas'))->assertOk();

        $csv = $this->get(route('inventario.valorizacion.index', ['exportar' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString($p->sku, $csv->streamedContent());
    }
}
