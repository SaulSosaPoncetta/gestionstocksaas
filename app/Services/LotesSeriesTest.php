<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Existencia;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\StockService;
use App\Services\TransferenciaService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test --filter=LotesSeriesTest
 */
class LotesSeriesTest extends TestCase
{
    use RefreshDatabase;

    protected StockService $stock;
    protected User $admin;
    protected Deposito $deposito;
    protected Deposito $sucursal;

    protected function setUp(): void
    {
        parent::setUp();

        EmpresaActual::seleccionar(null);

        $empresa = Empresa::create(['razon_social' => 'Prueba S.A.', 'cuit' => '30-71234567-1', 'usa_lotes' => true, 'usa_series' => true]);
        $this->admin = User::create([
            'empresa_id' => $empresa->id, 'name' => 'Admin', 'email' => 'admin@prueba.com',
            'password' => 'secreto123', 'rol' => User::ROL_ADMIN, 'activo' => true,
        ]);
        $this->actingAs($this->admin);

        $this->stock = app(StockService::class);
        $this->deposito = Deposito::principal();
        $this->sucursal = Deposito::create(['codigo' => 'SUC01', 'nombre' => 'Sucursal', 'activo' => true]);
    }

    protected function producto(array $datos = []): Producto
    {
        return Producto::create($datos + [
            'tipo' => Producto::TIPO_SIMPLE,
            'sku' => 'P' . uniqid(),
            'nombre' => 'Producto de prueba',
            'unidad_medida_id' => UnidadMedida::where('abreviatura', 'un')->value('id'),
            'alicuota_iva_id' => AlicuotaIva::where('codigo_afip', 5)->value('id'),
            'controla_stock' => true,
            'costo' => 100,
            'precio_venta' => 150,
            'activo' => true,
        ]);
    }

    protected function loteDe(Producto $p, string $codigo): Lote
    {
        return Lote::where('producto_id', $p->id)->where('codigo', $codigo)->firstOrFail();
    }

    protected function cantidadLote(Producto $p, string $codigo, ?Deposito $deposito = null): float
    {
        return (float) Existencia::where('producto_id', $p->id)
            ->where('lote_id', $this->loteDe($p, $codigo)->id)
            ->where('deposito_id', ($deposito ?? $this->deposito)->id)
            ->sum('cantidad');
    }

    /* ---------------- Lotes ---------------- */

    public function test_la_entrada_de_un_producto_con_lote_exige_lote(): void
    {
        $p = $this->producto(['usa_lotes' => true]);

        $this->expectException(ValidationException::class);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 5]], [], $this->admin);
    }

    public function test_crea_el_lote_y_lo_reutiliza(): void
    {
        $p = $this->producto(['usa_lotes' => true]);
        $vence = today()->addDays(60)->toDateString();

        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 5, 'lote_codigo' => 'a-1', 'lote_vencimiento' => $vence]], [], $this->admin);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3, 'lote_codigo' => 'A-1']], [], $this->admin);

        $this->assertSame(1, Lote::where('producto_id', $p->id)->count());
        $this->assertSame($vence, $this->loteDe($p, 'A-1')->fecha_vencimiento->toDateString());
        $this->assertEquals(8, $this->cantidadLote($p, 'A-1'));
    }

    public function test_no_acepta_otro_vencimiento_para_el_mismo_lote(): void
    {
        $p = $this->producto(['usa_lotes' => true]);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 5, 'lote_codigo' => 'A-1', 'lote_vencimiento' => today()->addDays(60)->toDateString()]], [], $this->admin);

        $this->expectException(ValidationException::class);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'lote_codigo' => 'A-1', 'lote_vencimiento' => today()->addDays(90)->toDateString()]], [], $this->admin);
    }

    public function test_la_salida_sin_lote_toma_primero_el_que_vence_antes(): void
    {
        $p = $this->producto(['usa_lotes' => true]);
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $p->id, 'cantidad' => 10, 'lote_codigo' => 'TARDE', 'lote_vencimiento' => today()->addDays(90)->toDateString()],
            ['producto_id' => $p->id, 'cantidad' => 4, 'lote_codigo' => 'PRONTO', 'lote_vencimiento' => today()->addDays(10)->toDateString()],
        ], [], $this->admin);

        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 6]], [], $this->admin);

        $this->assertEquals(0, $this->cantidadLote($p, 'PRONTO'));
        $this->assertEquals(8, $this->cantidadLote($p, 'TARDE'));
    }

    public function test_no_descuenta_solo_los_lotes_vencidos(): void
    {
        $p = $this->producto(['usa_lotes' => true]);
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $p->id, 'cantidad' => 5, 'lote_codigo' => 'VENCIDO', 'lote_vencimiento' => today()->subDays(3)->toDateString()],
            ['producto_id' => $p->id, 'cantidad' => 2, 'lote_codigo' => 'BUENO', 'lote_vencimiento' => today()->addDays(30)->toDateString()],
        ], [], $this->admin);

        try {
            $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 4]], [], $this->admin);
            $this->fail('No tendría que tomar el lote vencido automáticamente.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('vencidos', $e->errors()['lineas'][0]);
        }

        // Eligiendo el lote vencido sí se puede dar de baja
        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 5, 'lote_id' => $this->loteDe($p, 'VENCIDO')->id]], [], $this->admin);
        $this->assertEquals(0, $this->cantidadLote($p, 'VENCIDO'));
    }

    public function test_ajuste_por_conteo_de_un_lote(): void
    {
        $p = $this->producto(['usa_lotes' => true]);
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $p->id, 'cantidad' => 10, 'lote_codigo' => 'L1', 'lote_vencimiento' => today()->addDays(30)->toDateString()],
            ['producto_id' => $p->id, 'cantidad' => 5, 'lote_codigo' => 'L2', 'lote_vencimiento' => today()->addDays(60)->toDateString()],
        ], [], $this->admin);

        $this->stock->ajustarAContado($this->deposito->id, [['producto_id' => $p->id, 'lote_codigo' => 'L2', 'contado' => 7]], [], $this->admin);

        $this->assertEquals(7, $this->cantidadLote($p, 'L2'));
        $this->assertEquals(10, $this->cantidadLote($p, 'L1'));
    }

    public function test_la_transferencia_lleva_los_mismos_lotes(): void
    {
        $p = $this->producto(['usa_lotes' => true]);
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $p->id, 'cantidad' => 3, 'lote_codigo' => 'PRONTO', 'lote_vencimiento' => today()->addDays(10)->toDateString()],
            ['producto_id' => $p->id, 'cantidad' => 10, 'lote_codigo' => 'TARDE', 'lote_vencimiento' => today()->addDays(90)->toDateString()],
        ], [], $this->admin);

        $servicio = app(TransferenciaService::class);
        $t = $servicio->enviar($this->deposito->id, $this->sucursal->id, [['producto_id' => $p->id, 'cantidad' => 5]], [], $this->admin);

        $this->assertCount(2, $t->items, 'Un renglón por cada lote que viaja.');

        $recepcion = $t->items->mapWithKeys(fn ($i) => [$i->id => ['cantidad_recibida' => (float) $i->cantidad_enviada]])->all();
        $servicio->recibir($t, $recepcion, [], $this->admin);

        $this->assertEquals(3, $this->cantidadLote($p, 'PRONTO', $this->sucursal));
        $this->assertEquals(2, $this->cantidadLote($p, 'TARDE', $this->sucursal));
        $this->assertEquals(8, $this->cantidadLote($p, 'TARDE'));
    }

    /* ---------------- Series ---------------- */

    public function test_entrada_y_salida_por_numero_de_serie(): void
    {
        $p = $this->producto(['usa_series' => true]);

        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3, 'series' => "S1\nS2\nS3"]], [], $this->admin);
        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'series' => ['S2']]], [], $this->admin);

        $this->assertEquals(2, $this->stock->cantidad($p->id));
        $this->assertSame(Serie::EGRESADO, Serie::where('numero', 'S2')->value('estado'));
        $this->assertSame(Serie::EN_STOCK, Serie::where('numero', 'S1')->value('estado'));
        $this->assertCount(2, Serie::where('numero', 'S2')->first()->items, 'Historial: entró y salió.');
    }

    public function test_la_cantidad_tiene_que_coincidir_con_las_series(): void
    {
        $p = $this->producto(['usa_series' => true]);

        $this->expectException(ValidationException::class);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3, 'series' => ['S1', 'S2']]], [], $this->admin);
    }

    public function test_no_entra_dos_veces_la_misma_serie(): void
    {
        $p = $this->producto(['usa_series' => true]);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'series' => ['S1']]], [], $this->admin);

        $this->expectException(ValidationException::class);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'series' => ['S1']]], [], $this->admin);
    }

    public function test_no_sale_una_serie_que_no_esta(): void
    {
        $p = $this->producto(['usa_series' => true]);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'series' => ['S1']]], [], $this->admin);

        $this->expectException(ValidationException::class);
        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'series' => ['NO-EXISTE']]], [], $this->admin);
    }

    public function test_anular_una_venta_devuelve_la_serie_al_stock(): void
    {
        $p = $this->producto(['usa_series' => true]);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'series' => ['S1']]], [], $this->admin);
        $venta = $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1, 'series' => ['S1']]], [], $this->admin);

        $this->stock->anular($venta, $this->admin, 'Devolución');

        $serie = Serie::where('numero', 'S1')->first();
        $this->assertSame(Serie::EN_STOCK, $serie->estado);
        $this->assertSame($this->deposito->id, $serie->deposito_id);
    }

    public function test_transferencia_con_series_y_faltante(): void
    {
        $p = $this->producto(['usa_series' => true]);
        $this->stock->ingresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3, 'series' => ['S1', 'S2', 'S3']]], [], $this->admin);

        $servicio = app(TransferenciaService::class);
        $t = $servicio->enviar($this->deposito->id, $this->sucursal->id, [['producto_id' => $p->id, 'cantidad' => 2, 'series' => ['S1', 'S3']]], [], $this->admin);
        $item = $t->items->first();

        $this->assertSame(Deposito::transito()->id, Serie::where('numero', 'S1')->value('deposito_id'));

        // Llegó solo S1; S3 se registra como faltante
        $servicio->recibir($t, [$item->id => ['series_recibidas' => ['S1']]], ['destino_diferencias' => 'faltante'], $this->admin);

        $this->assertSame($this->sucursal->id, Serie::where('numero', 'S1')->value('deposito_id'));
        $this->assertSame(Serie::EGRESADO, Serie::where('numero', 'S3')->value('estado'));
        $this->assertSame($this->deposito->id, Serie::where('numero', 'S2')->value('deposito_id'));
        $this->assertEquals(1, $this->stock->cantidad($p->id, $this->sucursal->id));
    }
}
