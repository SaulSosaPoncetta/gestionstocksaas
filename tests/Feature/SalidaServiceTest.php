<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Cliente;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Producto;
use App\Models\Remito;
use App\Models\Reserva;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\SalidaService;
use App\Services\StockService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test --filter=SalidaServiceTest
 */
class SalidaServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SalidaService $salidas;
    protected StockService $stock;
    protected Empresa $empresa;
    protected User $admin;
    protected Deposito $deposito;
    protected Producto $producto;
    protected Cliente $cliente;

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

        $this->salidas = app(SalidaService::class);
        $this->stock = app(StockService::class);
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

        $this->cliente = Cliente::create(['razon_social' => 'Cliente S.A.', 'condicion_iva' => 'responsable_inscripto', 'activo' => true]);

        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $this->producto->id, 'cantidad' => 10, 'costo_unitario' => 100],
        ]);
    }

    protected function reservar(float $cantidad = 6, array $datos = []): Reserva
    {
        return $this->salidas->reservar($this->deposito->id, [
            ['producto_id' => $this->producto->id, 'cantidad' => $cantidad],
        ], ['cliente_id' => $this->cliente->id] + $datos, $this->admin);
    }

    protected function disponible(): float
    {
        return $this->stock->disponible($this->producto->id, $this->deposito->id);
    }

    public function test_la_reserva_baja_el_disponible_pero_no_el_stock(): void
    {
        $this->reservar(6);

        $this->assertEquals(10, $this->stock->cantidad($this->producto->id, $this->deposito->id));
        $this->assertEquals(4, $this->disponible());
    }

    public function test_no_se_puede_reservar_mas_de_lo_disponible(): void
    {
        $this->reservar(6);

        $this->expectException(ValidationException::class);
        $this->reservar(5);
    }

    public function test_un_egreso_comun_no_puede_consumir_lo_reservado(): void
    {
        $this->reservar(6);

        $this->stock->egresar($this->deposito->id, [['producto_id' => $this->producto->id, 'cantidad' => 4]]);
        $this->assertEquals(0, $this->disponible());

        $this->expectException(ValidationException::class);
        $this->stock->egresar($this->deposito->id, [['producto_id' => $this->producto->id, 'cantidad' => 1]]);
    }

    public function test_el_remito_de_una_reserva_entrega_y_descuenta(): void
    {
        $reserva = $this->reservar(6);
        $item = $reserva->items->first();

        $remito = $this->salidas->emitirRemito(['fecha' => today()->toDateString()], [
            ['producto_id' => $this->producto->id, 'cantidad' => 4, 'reserva_item_id' => $item->id],
        ], $reserva, $this->admin);

        $this->assertEquals(6, $this->stock->cantidad($this->producto->id, $this->deposito->id));
        $this->assertEquals(4, $this->disponible()); // 6 en stock − 2 que siguen reservados
        $this->assertEquals(Reserva::ACTIVA, $reserva->fresh()->estado);
        $this->assertEquals($this->cliente->id, $remito->cliente_id);
        $this->assertNotNull($remito->movimiento_id);

        // Segunda entrega: completa la reserva
        $this->salidas->emitirRemito(['fecha' => today()->toDateString()], [
            ['producto_id' => $this->producto->id, 'cantidad' => 2, 'reserva_item_id' => $item->id],
        ], $reserva->fresh(), $this->admin);

        $this->assertEquals(Reserva::ENTREGADA, $reserva->fresh()->estado);
        $this->assertEquals(4, $this->disponible());
    }

    public function test_no_se_entrega_mas_de_lo_pendiente_de_la_reserva(): void
    {
        $reserva = $this->reservar(3);

        $this->expectException(ValidationException::class);
        $this->salidas->emitirRemito(['fecha' => today()->toDateString()], [
            ['producto_id' => $this->producto->id, 'cantidad' => 5, 'reserva_item_id' => $reserva->items->first()->id],
        ], $reserva, $this->admin);
    }

    public function test_liberacion_parcial_y_total(): void
    {
        $reserva = $this->reservar(6);
        $item = $reserva->items->first();

        $this->salidas->liberar($reserva, [$item->id => 2], 'Pidió menos');
        $this->assertEquals(6, $this->disponible());
        $this->assertEquals(Reserva::ACTIVA, $reserva->fresh()->estado);

        $this->salidas->liberar($reserva->fresh(), null, 'Canceló');
        $this->assertEquals(10, $this->disponible());
        $this->assertEquals(Reserva::LIBERADA, $reserva->fresh()->estado);
    }

    public function test_las_reservas_vencidas_se_liberan_solas(): void
    {
        $vencida = $this->reservar(3, ['vence_at' => now()->subMinute()]);
        $vigente = $this->reservar(2, ['vence_at' => now()->addDay()]);

        $this->assertEquals(1, $this->salidas->liberarVencidas());
        $this->assertEquals(Reserva::VENCIDA, $vencida->fresh()->estado);
        $this->assertEquals(Reserva::ACTIVA, $vigente->fresh()->estado);
        $this->assertEquals(8, $this->disponible());
    }

    public function test_anular_el_remito_devuelve_el_stock_y_vuelve_a_reservar(): void
    {
        $reserva = $this->reservar(6);
        $item = $reserva->items->first();

        $remito = $this->salidas->emitirRemito(['fecha' => today()->toDateString()], [
            ['producto_id' => $this->producto->id, 'cantidad' => 6, 'reserva_item_id' => $item->id],
        ], $reserva, $this->admin);
        $this->assertEquals(Reserva::ENTREGADA, $reserva->fresh()->estado);

        $this->salidas->anularRemito($remito, $this->admin, 'Rechazado');

        $this->assertEquals(Remito::ANULADO, $remito->fresh()->estado);
        $this->assertEquals(10, $this->stock->cantidad($this->producto->id, $this->deposito->id));
        $this->assertEquals(4, $this->disponible());
        $this->assertEquals(Reserva::ACTIVA, $reserva->fresh()->estado);
    }

    public function test_el_movimiento_del_remito_no_se_anula_por_separado(): void
    {
        $remito = $this->salidas->emitirRemito([
            'deposito_id' => $this->deposito->id,
            'fecha' => today()->toDateString(),
        ], [['producto_id' => $this->producto->id, 'cantidad' => 2]], null, $this->admin);

        $this->expectException(ValidationException::class);
        $this->stock->anular($remito->movimiento, $this->admin, 'Intento manual');
    }

    public function test_reserva_y_remito_son_idempotentes_por_referencia(): void
    {
        $a = $this->reservar(2, ['referencia_externa' => 'PED-1']);
        $b = $this->reservar(2, ['referencia_externa' => 'PED-1']);
        $this->assertEquals($a->id, $b->id);
        $this->assertEquals(8, $this->disponible());

        $datos = ['deposito_id' => $this->deposito->id, 'fecha' => today()->toDateString(), 'referencia_externa' => 'FAC-1'];
        $linea = [['producto_id' => $this->producto->id, 'cantidad' => 1]];
        $r1 = $this->salidas->emitirRemito($datos, $linea, null, $this->admin);
        $r2 = $this->salidas->emitirRemito($datos, $linea, null, $this->admin);

        $this->assertEquals($r1->id, $r2->id);
        $this->assertEquals(9, $this->stock->cantidad($this->producto->id, $this->deposito->id));
    }
}
