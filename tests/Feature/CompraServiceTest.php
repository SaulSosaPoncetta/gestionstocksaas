<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\CompraService;
use App\Services\StockService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test --filter=CompraServiceTest
 */
class CompraServiceTest extends TestCase
{
    use RefreshDatabase;

    protected CompraService $compras;
    protected StockService $stock;
    protected Empresa $empresa;
    protected User $admin;
    protected Deposito $deposito;
    protected Proveedor $proveedor;
    protected Producto $producto;
    protected ProductoPresentacion $caja;

    protected function setUp(): void
    {
        parent::setUp();

        EmpresaActual::seleccionar(null);

        $this->empresa = Empresa::create(['razon_social' => 'Prueba S.A.', 'cuit' => '30-71234567-1']);
        $this->admin = User::create([
            'empresa_id' => $this->empresa->id, 'name' => 'Admin', 'email' => 'admin@prueba.com',
            'password' => 'secreto123', 'rol' => User::ROL_ADMIN, 'activo' => true,
        ]);
        $this->actingAs($this->admin);

        $this->compras = app(CompraService::class);
        $this->stock = app(StockService::class);
        $this->deposito = Deposito::principal();
        $this->proveedor = Proveedor::create(['razon_social' => 'Proveedor S.A.', 'activo' => true]);

        $this->producto = Producto::create([
            'tipo' => Producto::TIPO_SIMPLE,
            'sku' => 'P0001',
            'nombre' => 'Producto de prueba',
            'unidad_medida_id' => UnidadMedida::where('abreviatura', 'un')->value('id'),
            'alicuota_iva_id' => AlicuotaIva::where('codigo_afip', 5)->value('id'), // 21%
            'controla_stock' => true,
            'costo' => 100,
            'margen' => 50,
            'precio_venta' => 150,
            'stock_minimo' => 5,
            'punto_reposicion' => 10,
            'stock_maximo' => 30,
            'activo' => true,
        ]);

        $this->caja = ProductoPresentacion::create(['producto_id' => $this->producto->id, 'nombre' => 'Caja x 12', 'cantidad' => 12, 'activa' => true]);
    }

    protected function orden(float $cajas = 2, float $costoCaja = 1200): OrdenCompra
    {
        return $this->compras->guardarOrden(null, [
            'proveedor_id' => $this->proveedor->id,
            'deposito_id' => $this->deposito->id,
        ], [
            ['producto_id' => $this->producto->id, 'presentacion_id' => $this->caja->id, 'cantidad' => $cajas, 'costo_unitario' => $costoCaja],
        ], $this->admin);
    }

    public function test_la_orden_pasa_la_presentacion_a_unidades_y_calcula_el_iva(): void
    {
        $orden = $this->orden(2, 1200);
        $item = $orden->items->first();

        $this->assertEquals(24, (float) $item->cantidad);
        $this->assertEquals(100, (float) $item->costo_unitario);
        $this->assertEquals(2400, (float) $orden->fresh()->subtotal);
        $this->assertEquals(504, (float) $orden->fresh()->iva);
        $this->assertEquals(2904, (float) $orden->fresh()->total);
        $this->assertSame(OrdenCompra::BORRADOR, $orden->estado);
    }

    public function test_recepcion_parcial_y_luego_completa(): void
    {
        $orden = $this->orden(2, 1200);
        $item = $orden->items->first();

        $this->compras->recibir($orden, [$item->id => ['cantidad' => 10, 'costo_unitario' => 110]], ['actualizar_costos' => true, 'actualizar_precios' => true], $this->admin);

        $this->assertSame(OrdenCompra::PARCIAL, $orden->fresh()->estado);
        $this->assertEquals(10, $this->stock->cantidad($this->producto->id));
        $this->assertEquals(110, (float) $this->producto->fresh()->costo_promedio);
        $this->assertEquals(110, (float) $this->producto->fresh()->costo);
        $this->assertEquals(165, (float) $this->producto->fresh()->precio_venta);
        $this->assertEquals(110, (float) ProductoProveedor::where('producto_id', $this->producto->id)->value('costo'));

        $this->compras->recibir($orden, [$item->id => ['cantidad' => 14]], [], $this->admin);

        $this->assertSame(OrdenCompra::RECIBIDA, $orden->fresh()->estado);
        $this->assertEquals(24, $this->stock->cantidad($this->producto->id));
    }

    public function test_no_recibe_mas_de_lo_pendiente(): void
    {
        $orden = $this->orden(1);
        $item = $orden->items->first();

        $this->expectException(ValidationException::class);
        $this->compras->recibir($orden, [$item->id => ['cantidad' => 13]], [], $this->admin);
    }

    public function test_anular_la_recepcion_saca_el_stock_y_vuelve_a_quedar_pendiente(): void
    {
        $orden = $this->orden(1);
        $item = $orden->items->first();
        $recepcion = $this->compras->recibir($orden, [$item->id => ['cantidad' => 12]], [], $this->admin);

        $this->compras->anularRecepcion($recepcion, $this->admin, 'Se cargó en la orden equivocada');

        $this->assertEquals(0, $this->stock->cantidad($this->producto->id));
        $this->assertEquals(0, (float) $item->fresh()->cantidad_recibida);
        $this->assertTrue($orden->fresh()->estaAbierta());
    }

    public function test_el_movimiento_de_una_recepcion_no_se_anula_suelto(): void
    {
        $orden = $this->orden(1);
        $item = $orden->items->first();
        $recepcion = $this->compras->recibir($orden, [$item->id => ['cantidad' => 12]], [], $this->admin);

        $this->expectException(ValidationException::class);
        $this->stock->anular($recepcion->movimiento, $this->admin, 'Intento manual');
    }

    public function test_cerrar_una_orden_parcial(): void
    {
        $orden = $this->orden(2);
        $item = $orden->items->first();
        $this->compras->recibir($orden, [$item->id => ['cantidad' => 12]], [], $this->admin);

        $this->compras->cerrar($orden->fresh(), 'El proveedor no tiene más');

        $this->assertSame(OrdenCompra::CERRADA, $orden->fresh()->estado);
        $this->assertFalse($orden->fresh()->estaAbierta());
    }

    public function test_sugerencia_de_reposicion_descuenta_lo_pedido_y_redondea_a_la_caja(): void
    {
        ProductoProveedor::create([
            'producto_id' => $this->producto->id, 'proveedor_id' => $this->proveedor->id,
            'presentacion_id' => $this->caja->id, 'costo' => 100, 'es_principal' => true,
        ]);

        // Stock 4: llegó al punto de reposición (10). Objetivo: máximo 30 → faltan 26 → 3 cajas = 36
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $this->producto->id, 'cantidad' => 4]], [], $this->admin);

        $sugerencia = $this->compras->sugerencias([$this->deposito->id])->first();

        $this->assertNotNull($sugerencia);
        $this->assertEquals(36, $sugerencia->sugerido);
        $this->assertTrue($sugerencia->urgente);
        $this->assertSame($this->proveedor->id, $sugerencia->proveedor_id);

        // Con una orden abierta de 2 cajas (24): 4 + 24 = 28 > 10 → ya no se sugiere
        $this->orden(2);
        $this->assertTrue($this->compras->sugerencias([$this->deposito->id])->isEmpty());
    }

    public function test_generar_ordenes_desde_la_sugerencia(): void
    {
        $otro = Proveedor::create(['razon_social' => 'Otro proveedor', 'activo' => true]);
        $segundo = Producto::create([
            'tipo' => Producto::TIPO_SIMPLE, 'sku' => 'P0002', 'nombre' => 'Segundo',
            'unidad_medida_id' => UnidadMedida::where('abreviatura', 'un')->value('id'),
            'alicuota_iva_id' => AlicuotaIva::where('codigo_afip', 5)->value('id'),
            'controla_stock' => true, 'costo' => 50, 'precio_venta' => 80, 'activo' => true,
        ]);

        $ordenes = $this->compras->generarOrdenes([
            ['producto_id' => $this->producto->id, 'proveedor_id' => $this->proveedor->id, 'cantidad' => 12],
            ['producto_id' => $segundo->id, 'proveedor_id' => $otro->id, 'cantidad' => 5],
        ], $this->deposito->id, $this->admin);

        $this->assertCount(2, $ordenes);
        $this->assertSame(2, OrdenCompra::where('estado', OrdenCompra::BORRADOR)->count());
    }

    public function test_sin_el_modulo_no_se_entra_a_compras(): void
    {
        $this->empresa->update(['modulos' => ['compras' => false]]);
        EmpresaActual::seleccionar(null);

        $this->get(route('compras.ordenes.index'))->assertRedirect(route('dashboard'));
    }
}
