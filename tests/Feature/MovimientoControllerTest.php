<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\StockService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * php artisan test --filter=MovimientoControllerTest
 */
class MovimientoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected Empresa $empresa;
    protected User $admin;
    protected User $operador;
    protected Deposito $deposito;
    protected Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        EmpresaActual::seleccionar(null);

        $this->empresa = Empresa::create(['razon_social' => 'Prueba S.A.', 'cuit' => '30-71234567-1']);

        $this->admin = User::create([
            'empresa_id' => $this->empresa->id, 'name' => 'Admin', 'email' => 'admin@prueba.com',
            'password' => 'secreto123', 'rol' => User::ROL_ADMIN, 'activo' => true,
        ]);

        $this->operador = User::create([
            'empresa_id' => $this->empresa->id, 'name' => 'Operador', 'email' => 'operador@prueba.com',
            'password' => 'secreto123', 'rol' => User::ROL_OPERADOR, 'activo' => true,
        ]);

        $this->actingAs($this->admin);
        $this->deposito = Deposito::principal();

        $this->producto = Producto::create([
            'tipo' => Producto::TIPO_SIMPLE,
            'sku' => 'P0001',
            'nombre' => 'Producto de prueba',
            'unidad_medida_id' => UnidadMedida::where('abreviatura', 'un')->value('id'),
            'alicuota_iva_id' => AlicuotaIva::where('codigo_afip', 5)->value('id'),
            'controla_stock' => true,
            'costo' => 100,
            'precio_venta' => 150,
            'activo' => true,
        ]);

        app(StockService::class)->cargarInicial($this->deposito->id, [
            ['producto_id' => $this->producto->id, 'cantidad' => 10, 'costo_unitario' => 100],
        ], [], $this->admin);
    }

    protected function motivo(string $tipo, string $nombre): Motivo
    {
        return Motivo::where('tipo', $tipo)->where('nombre', $nombre)->firstOrFail();
    }

    public function test_el_operador_registra_un_egreso(): void
    {
        $respuesta = $this->actingAs($this->operador)->post(route('inventario.movimientos.store', 'egreso'), [
            'deposito_id' => $this->deposito->id,
            'motivo_id' => $this->motivo('egreso', 'Consumo interno')->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 4]],
        ]);

        $respuesta->assertRedirect();
        $this->assertEquals(6, app(StockService::class)->cantidad($this->producto->id));
    }

    public function test_el_operador_no_puede_ajustar(): void
    {
        $this->actingAs($this->operador)
            ->get(route('inventario.movimientos.create', 'ajuste'))
            ->assertForbidden();
    }

    public function test_motivo_con_observacion_obligatoria(): void
    {
        $this->post(route('inventario.movimientos.store', 'egreso'), [
            'deposito_id' => $this->deposito->id,
            'motivo_id' => $this->motivo('egreso', 'Robo / faltante')->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 1]],
        ])->assertSessionHasErrors('observaciones');

        $this->assertSame(1, Movimiento::count());
    }

    public function test_ajuste_por_conteo_desde_la_pantalla(): void
    {
        $this->post(route('inventario.movimientos.store', 'ajuste'), [
            'deposito_id' => $this->deposito->id,
            'motivo_id' => $this->motivo('ajuste', 'Diferencia de inventario')->id,
            'modo_ajuste' => 'contado',
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 12]],
        ])->assertRedirect();

        $this->assertEquals(12, app(StockService::class)->cantidad($this->producto->id));
    }

    public function test_ingreso_sin_costo_no_cambia_el_costo_promedio(): void
    {
        // "Devolución de cliente" no pide costo: aunque venga un costo, se ignora
        $this->post(route('inventario.movimientos.store', 'ingreso'), [
            'deposito_id' => $this->deposito->id,
            'motivo_id' => $this->motivo('ingreso', 'Devolución de cliente')->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 10, 'costo_unitario' => 999]],
        ])->assertRedirect();

        $this->assertEquals(100, (float) $this->producto->fresh()->costo_promedio);
        $this->assertEquals(20, app(StockService::class)->cantidad($this->producto->id));
    }

    public function test_compra_recalcula_el_costo_promedio(): void
    {
        $this->post(route('inventario.movimientos.store', 'ingreso'), [
            'deposito_id' => $this->deposito->id,
            'motivo_id' => $this->motivo('ingreso', 'Compra a proveedor')->id,
            'lineas' => [['producto_id' => $this->producto->id, 'cantidad' => 10, 'costo_unitario' => 200]],
        ])->assertRedirect();

        $this->assertEquals(150, (float) $this->producto->fresh()->costo_promedio);
    }
}
