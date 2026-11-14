<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Lote;
use App\Models\Producto;
use App\Models\Serie;
use App\Models\TomaInventario;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\StockService;
use App\Services\TomaInventarioService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test --filter=TomaInventarioTest
 */
class TomaInventarioTest extends TestCase
{
    use RefreshDatabase;

    protected TomaInventarioService $tomas;
    protected StockService $stock;
    protected User $admin;
    protected Deposito $deposito;

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

        $this->tomas = app(TomaInventarioService::class);
        $this->stock = app(StockService::class);
        $this->deposito = Deposito::principal();
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

    protected function iniciar(array $datos = []): TomaInventario
    {
        return $this->tomas->iniciar($datos + ['deposito_id' => $this->deposito->id, 'congela' => true], $this->admin);
    }

    protected function cantidad(Producto $p): float
    {
        return $this->stock->cantidad($p->id, $this->deposito->id);
    }

    public function test_arma_la_lista_con_lo_que_dice_el_sistema(): void
    {
        $a = $this->producto();
        $b = $this->producto();
        $this->producto(); // sin stock: no aparece
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $a->id, 'cantidad' => 10, 'costo_unitario' => 100],
            ['producto_id' => $b->id, 'cantidad' => 4, 'costo_unitario' => 50],
        ]);

        $toma = $this->iniciar();

        $this->assertCount(2, $toma->items);
        $this->assertEquals(10, (float) $toma->items->firstWhere('producto_id', $a->id)->teorico);

        $conSinStock = $this->tomas->cancelar($toma, 'prueba', $this->admin);
        $this->assertSame(TomaInventario::CANCELADA, $conSinStock->estado);
        $this->assertCount(3, $this->iniciar(['incluir_sin_stock' => true])->items);
    }

    public function test_cerrar_ajusta_faltantes_y_sobrantes_en_un_solo_movimiento(): void
    {
        $a = $this->producto();
        $b = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $a->id, 'cantidad' => 10, 'costo_unitario' => 100],
            ['producto_id' => $b->id, 'cantidad' => 4, 'costo_unitario' => 50],
        ]);

        $toma = $this->iniciar();
        $ia = $toma->items->firstWhere('producto_id', $a->id);
        $ib = $toma->items->firstWhere('producto_id', $b->id);

        $this->tomas->registrarConteo($toma, [$ia->id => ['contado' => '7'], $ib->id => ['contado' => '6']], $this->admin);
        $toma = $this->tomas->cerrar($toma, 'ignorar', $this->admin);

        $this->assertSame(TomaInventario::CERRADA, $toma->estado);
        $this->assertNotNull($toma->movimiento_id);
        $this->assertEquals(7, $this->cantidad($a));
        $this->assertEquals(6, $this->cantidad($b));
        $this->assertEquals(300, (float) $toma->valor_faltante);
        $this->assertEquals(100, (float) $toma->valor_sobrante);
        $this->assertSame(2, $toma->lineas_con_diferencia);
    }

    public function test_el_deposito_congelado_no_acepta_movimientos_hasta_cerrar(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 10, 'costo_unitario' => 100]]);

        $toma = $this->iniciar(['congela' => true]);

        try {
            $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1]]);
            $this->fail('Debería haber rechazado el egreso en un depósito congelado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('congelado', collect($e->errors())->flatten()->implode(' '));
        }

        $this->tomas->cerrar($toma, 'ignorar', $this->admin);

        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 1]]);
        $this->assertEquals(9, $this->cantidad($p));
    }

    public function test_sin_congelar_respeta_lo_que_se_movio_despues_de_contar(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 10, 'costo_unitario' => 100]]);

        $toma = $this->iniciar(['congela' => false]);
        $item = $toma->items->first();

        // Se vendieron 2 antes de contar y 3 después de contar
        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 2]]);
        $this->tomas->registrarConteo($toma, [$item->id => ['contado' => '7']], $this->admin); // faltaba 1
        $this->stock->egresar($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3]]);

        $this->tomas->cerrar($toma, 'ignorar', $this->admin);

        // 10 − 2 − 1 (faltante) − 3 = 4
        $this->assertEquals(4, $this->cantidad($p));
    }

    public function test_lo_no_contado_se_ignora_o_se_da_como_faltante(): void
    {
        $a = $this->producto();
        $b = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [
            ['producto_id' => $a->id, 'cantidad' => 5, 'costo_unitario' => 100],
            ['producto_id' => $b->id, 'cantidad' => 5, 'costo_unitario' => 100],
        ]);

        $this->tomas->cerrar($this->iniciar(), 'ignorar', $this->admin);
        $this->assertEquals(5, $this->cantidad($a));

        $toma = $this->iniciar();
        $this->tomas->registrarConteo($toma, [$toma->items->firstWhere('producto_id', $a->id)->id => ['contado' => '5']], $this->admin);
        $this->tomas->cerrar($toma, 'cero', $this->admin);

        $this->assertEquals(5, $this->cantidad($a));
        $this->assertEquals(0, $this->cantidad($b));
    }

    public function test_sin_diferencias_cierra_sin_movimiento(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3, 'costo_unitario' => 100]]);

        $toma = $this->iniciar();
        $this->tomas->registrarConteo($toma, [$toma->items->first()->id => ['contado' => '3']], $this->admin);
        $toma = $this->tomas->cerrar($toma, 'ignorar', $this->admin);

        $this->assertSame(TomaInventario::CERRADA, $toma->estado);
        $this->assertNull($toma->movimiento_id);
    }

    public function test_un_lote_que_aparecio_se_agrega_y_se_crea_al_cerrar(): void
    {
        $p = $this->producto(['usa_lotes' => true]);
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 5, 'costo_unitario' => 100, 'lote_codigo' => 'L1']]);

        $toma = $this->iniciar();

        $this->tomas->registrarConteo($toma, [$toma->items->first()->id => ['contado' => '4']], $this->admin);
        $this->tomas->agregarRenglon($toma, ['producto_id' => $p->id, 'lote_codigo' => 'l2', 'contado' => '3'], $this->admin);
        $this->tomas->cerrar($toma, 'ignorar', $this->admin);

        $l2 = Lote::where('producto_id', $p->id)->where('codigo', 'L2')->first();
        $this->assertNotNull($l2);
        $this->assertEquals(7, $this->cantidad($p));
    }

    public function test_un_sobrante_de_producto_con_lote_exige_indicar_el_lote(): void
    {
        $p = $this->producto(['usa_lotes' => true]);

        $toma = $this->iniciar(['incluir_sin_stock' => true]); // renglón del producto sin lote, en cero
        $item = $toma->items->firstWhere('producto_id', $p->id);

        $this->expectException(ValidationException::class);
        $this->tomas->registrarConteo($toma, [$item->id => ['contado' => '2']], $this->admin);
    }

    public function test_series_faltantes_y_sobrantes(): void
    {
        $p = $this->producto(['usa_series' => true]);
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 3, 'costo_unitario' => 100, 'series' => ['S1', 'S2', 'S3']]]);

        $toma = $this->iniciar();
        $item = $toma->items->first();
        $this->assertSame(['S1', 'S2', 'S3'], $item->series_teoricas);

        // Falta S2 y apareció S9
        $this->tomas->registrarConteo($toma, [$item->id => ['series' => "S1\nS3\nS9"]], $this->admin);
        $this->tomas->cerrar($toma, 'ignorar', $this->admin);

        $this->assertEquals(3, $this->cantidad($p));
        $this->assertFalse(Serie::where('producto_id', $p->id)->where('numero', 'S2')->first()->enStock());
        $this->assertTrue(Serie::where('producto_id', $p->id)->where('numero', 'S9')->first()->enStock());
    }

    public function test_una_sola_toma_en_curso_por_deposito(): void
    {
        $this->iniciar();

        $this->expectException(ValidationException::class);
        $this->iniciar();
    }

    public function test_acepta_decimales_con_coma_y_rechaza_negativos(): void
    {
        $kg = UnidadMedida::where('permite_decimales', true)->value('id');
        $p = $this->producto(['unidad_medida_id' => $kg]);
        $this->stock->cargarInicial($this->deposito->id, [['producto_id' => $p->id, 'cantidad' => 10, 'costo_unitario' => 100]]);

        $toma = $this->iniciar();
        $item = $toma->items->first();

        $this->tomas->registrarConteo($toma, [$item->id => ['contado' => '9,5']], $this->admin);
        $this->assertEquals(9.5, (float) $item->fresh()->contado);

        $this->expectException(ValidationException::class);
        $this->tomas->registrarConteo($toma, [$item->id => ['contado' => '-1']], $this->admin);
    }
}
