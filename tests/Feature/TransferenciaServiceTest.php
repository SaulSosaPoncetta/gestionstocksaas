<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Movimiento;
use App\Models\Producto;
use App\Models\Transferencia;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\StockService;
use App\Services\TransferenciaService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test --filter=TransferenciaServiceTest
 */
class TransferenciaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockService $stock;
    protected TransferenciaService $transferencias;
    protected User $admin;
    protected Deposito $origen;
    protected Deposito $destino;
    protected Producto $producto;

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

        $this->stock = app(StockService::class);
        $this->transferencias = app(TransferenciaService::class);

        $this->origen = Deposito::principal();
        $this->destino = Deposito::create(['codigo' => 'SUC01', 'nombre' => 'Sucursal', 'activo' => true]);

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

        $this->stock->cargarInicial($this->origen->id, [
            ['producto_id' => $this->producto->id, 'cantidad' => 20, 'costo_unitario' => 100],
        ], [], $this->admin);
    }

    protected function cantidad(Deposito $deposito): float
    {
        return $this->stock->cantidad($this->producto->id, $deposito->id);
    }

    protected function enviar(float $cantidad): Transferencia
    {
        return $this->transferencias->enviar($this->origen->id, $this->destino->id, [
            ['producto_id' => $this->producto->id, 'cantidad' => $cantidad],
        ], [], $this->admin);
    }

    public function test_el_envio_deja_la_mercaderia_en_transito(): void
    {
        $t = $this->enviar(8);

        $this->assertSame(Transferencia::EN_TRANSITO, $t->estado);
        $this->assertEquals(12, $this->cantidad($this->origen));
        $this->assertEquals(0, $this->cantidad($this->destino));
        $this->assertEquals(8, $this->cantidad(Deposito::transito()));
    }

    public function test_recepcion_completa(): void
    {
        $t = $this->enviar(8);
        $item = $t->items->first();

        $t = $this->transferencias->recibir($t, [$item->id => ['cantidad_recibida' => 8]], [], $this->admin);

        $this->assertSame(Transferencia::RECIBIDA, $t->estado);
        $this->assertEquals(8, $this->cantidad($this->destino));
        $this->assertEquals(0, $this->cantidad(Deposito::transito()));
    }

    public function test_recepcion_con_faltante(): void
    {
        $t = $this->enviar(8);
        $item = $t->items->first();

        $t = $this->transferencias->recibir($t, [$item->id => ['cantidad_recibida' => 6]], ['destino_diferencias' => 'faltante'], $this->admin);

        $this->assertSame(Transferencia::CON_DIFERENCIAS, $t->estado);
        $this->assertEquals(6, $this->cantidad($this->destino));
        $this->assertEquals(12, $this->cantidad($this->origen));
        $this->assertEquals(0, $this->cantidad(Deposito::transito()));
        $this->assertTrue($t->movimientos()->where('tipo', Movimiento::TIPO_EGRESO)->exists());
    }

    public function test_recepcion_con_devolucion_al_origen(): void
    {
        $t = $this->enviar(8);
        $item = $t->items->first();

        $this->transferencias->recibir($t, [$item->id => ['cantidad_recibida' => 5]], ['destino_diferencias' => 'devolver'], $this->admin);

        $this->assertEquals(5, $this->cantidad($this->destino));
        $this->assertEquals(15, $this->cantidad($this->origen));
        $this->assertEquals(0, $this->cantidad(Deposito::transito()));
    }

    public function test_no_se_recibe_mas_de_lo_enviado(): void
    {
        $t = $this->enviar(8);
        $item = $t->items->first();

        $this->expectException(ValidationException::class);
        $this->transferencias->recibir($t, [$item->id => ['cantidad_recibida' => 9]], [], $this->admin);
    }

    public function test_no_se_recibe_dos_veces(): void
    {
        $t = $this->enviar(8);
        $item = $t->items->first();
        $this->transferencias->recibir($t, [$item->id => ['cantidad_recibida' => 8]], [], $this->admin);

        $this->expectException(ValidationException::class);
        $this->transferencias->recibir($t, [$item->id => ['cantidad_recibida' => 8]], [], $this->admin);
    }

    public function test_anular_en_transito_devuelve_al_origen(): void
    {
        $t = $this->enviar(8);

        $t = $this->transferencias->anular($t, $this->admin, 'Se suspendió el envío');

        $this->assertSame(Transferencia::ANULADA, $t->estado);
        $this->assertEquals(20, $this->cantidad($this->origen));
        $this->assertEquals(0, $this->cantidad(Deposito::transito()));
    }

    public function test_no_se_envia_mas_de_lo_que_hay(): void
    {
        try {
            $this->enviar(25);
            $this->fail('Tendría que rechazar el envío.');
        } catch (ValidationException) {
            // ok
        }

        $this->assertEquals(20, $this->cantidad($this->origen));
        $this->assertSame(0, Transferencia::count());
    }

    public function test_los_movimientos_de_una_transferencia_no_se_anulan_sueltos(): void
    {
        $t = $this->enviar(8);
        $envio = $t->movimientos()->first();

        $this->expectException(ValidationException::class);
        $this->stock->anular($envio, $this->admin, 'Intento manual');
    }

    public function test_el_deposito_en_transito_no_se_puede_usar_a_mano(): void
    {
        $this->enviar(8);

        $this->expectException(ValidationException::class);
        $this->stock->egresar(Deposito::transito()->id, [
            ['producto_id' => $this->producto->id, 'cantidad' => 1],
        ], [], $this->admin);
    }
}
