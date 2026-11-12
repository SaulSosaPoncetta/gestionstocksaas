<?php

namespace Tests\Feature;

use App\Models\AlicuotaIva;
use App\Models\Deposito;
use App\Models\Empresa;
use App\Models\Existencia;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\MovimientoItem;
use App\Models\Producto;
use App\Models\Ubicacion;
use App\Models\UnidadMedida;
use App\Models\User;
use App\Services\StockService;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * php artisan test --filter=StockServiceTest
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    protected StockService $stock;
    protected Empresa $empresa;
    protected User $admin;
    protected Deposito $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        EmpresaActual::seleccionar(null); // limpia la caché entre tests

        $this->empresa = Empresa::create([
            'razon_social' => 'Prueba S.A.',
            'cuit' => '30-71234567-1',
            'permite_stock_negativo' => false,
        ]);

        $this->admin = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Admin',
            'email' => 'admin@prueba.com',
            'password' => 'secreto123',
            'rol' => User::ROL_ADMIN,
            'activo' => true,
        ]);

        $this->actingAs($this->admin);

        $this->deposito = Deposito::principal();
        $this->stock = app(StockService::class);
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

    protected function linea(Producto $producto, float $cantidad, array $extra = []): array
    {
        return ['producto_id' => $producto->id, 'cantidad' => $cantidad] + $extra;
    }

    public function test_ingresos_y_egresos_actualizan_existencia_y_kardex(): void
    {
        $p = $this->producto();

        $primero = $this->stock->cargarInicial($this->deposito->id, [$this->linea($p, 10, ['costo_unitario' => 100])], [], $this->admin);
        $this->stock->ingresar($this->deposito->id, [$this->linea($p, 5, ['costo_unitario' => 100])], [], $this->admin);
        $this->stock->egresar($this->deposito->id, [$this->linea($p, 3)], [], $this->admin);

        $this->assertSame(1, $primero->numero);
        $this->assertEquals(12, $this->stock->cantidad($p->id, $this->deposito->id));

        $saldos = MovimientoItem::where('producto_id', $p->id)->orderBy('id')->pluck('saldo')->map(fn ($s) => (float) $s)->all();
        $this->assertSame([10.0, 15.0, 12.0], $saldos);
        $this->assertSame(3, Movimiento::count());
    }

    public function test_no_deja_sacar_mas_de_lo_que_hay(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [$this->linea($p, 2)], [], $this->admin);

        try {
            $this->stock->egresar($this->deposito->id, [$this->linea($p, 5)], [], $this->admin);
            $this->fail('Tendría que haber rechazado la salida.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Stock insuficiente', $e->errors()['lineas'][0]);
        }

        $this->assertEquals(2, $this->stock->cantidad($p->id));
        $this->assertSame(1, Movimiento::count(), 'El movimiento rechazado no tiene que dejar rastro.');
    }

    public function test_deposito_que_permite_negativo(): void
    {
        $this->deposito->update(['permite_stock_negativo' => true]);
        $p = $this->producto();

        $this->stock->egresar($this->deposito->id, [$this->linea($p, 3)], [], $this->admin);

        $this->assertEquals(-3, $this->stock->cantidad($p->id));
    }

    public function test_costo_promedio_ponderado(): void
    {
        $p = $this->producto();

        $this->stock->ingresar($this->deposito->id, [$this->linea($p, 10, ['costo_unitario' => 100])], [], $this->admin);
        $this->stock->ingresar($this->deposito->id, [$this->linea($p, 10, ['costo_unitario' => 200])], [], $this->admin);
        $salida = $this->stock->egresar($this->deposito->id, [$this->linea($p, 5)], [], $this->admin);

        $this->assertEquals(150, (float) $p->fresh()->costo_promedio);
        $this->assertEquals(150, (float) $salida->items->first()->costo_unitario);
    }

    public function test_la_misma_referencia_no_se_registra_dos_veces(): void
    {
        $p = $this->producto();
        $opciones = ['referencia_externa' => 'comercial:factura:1532', 'origen' => 'comercial'];

        $uno = $this->stock->ingresar($this->deposito->id, [$this->linea($p, 4)], $opciones);
        $dos = $this->stock->ingresar($this->deposito->id, [$this->linea($p, 4)], $opciones);

        $this->assertSame($uno->id, $dos->id);
        $this->assertEquals(4, $this->stock->cantidad($p->id));
    }

    public function test_anular_revierte_el_stock_y_no_se_anula_dos_veces(): void
    {
        $p = $this->producto();
        $ingreso = $this->stock->ingresar($this->deposito->id, [$this->linea($p, 8, ['costo_unitario' => 100])], [], $this->admin);

        $contra = $this->stock->anular($ingreso, $this->admin, 'Cargado por error');

        $this->assertEquals(0, $this->stock->cantidad($p->id));
        $this->assertSame(Movimiento::TIPO_ANULACION, $contra->tipo);
        $this->assertSame(Movimiento::ESTADO_ANULADO, $ingreso->fresh()->estado);
        $this->assertSame($contra->id, $ingreso->fresh()->anulado_por_id);

        $this->expectException(ValidationException::class);
        $this->stock->anular($ingreso->fresh(), $this->admin);
    }

    public function test_no_deja_anular_un_ingreso_ya_consumido(): void
    {
        $p = $this->producto();
        $ingreso = $this->stock->ingresar($this->deposito->id, [$this->linea($p, 5)], [], $this->admin);
        $this->stock->egresar($this->deposito->id, [$this->linea($p, 4)], [], $this->admin);

        $this->expectException(ValidationException::class);
        $this->stock->anular($ingreso, $this->admin);
    }

    public function test_salida_sin_ubicacion_toma_de_las_ubicaciones_con_stock(): void
    {
        $this->deposito->update(['usa_ubicaciones' => true]);
        $estante = Ubicacion::create(['deposito_id' => $this->deposito->id, 'tipo' => 'posicion', 'codigo' => 'A-01-1']);
        $p = $this->producto();

        $this->stock->cargarInicial($this->deposito->id, [
            $this->linea($p, 5, ['ubicacion_id' => $estante->id]),
            $this->linea($p, 3),
        ], [], $this->admin);

        $salida = $this->stock->egresar($this->deposito->id, [$this->linea($p, 6)], [], $this->admin);

        $enEstante = Existencia::where('producto_id', $p->id)->where('ubicacion_id', $estante->id)->value('cantidad');
        $sinUbicar = Existencia::where('producto_id', $p->id)->whereNull('ubicacion_id')->value('cantidad');

        $this->assertEquals(2, $enEstante);
        $this->assertEquals(0, $sinUbicar);
        $this->assertCount(2, $salida->items);
    }

    public function test_transferencia_entre_depositos(): void
    {
        $otro = Deposito::create(['codigo' => 'DEP02', 'nombre' => 'Otro depósito', 'activo' => true]);
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [$this->linea($p, 10)], [], $this->admin);

        $transferencia = $this->stock->transferir($this->deposito->id, $otro->id, [$this->linea($p, 4)], [], $this->admin);

        $this->assertEquals(6, $this->stock->cantidad($p->id, $this->deposito->id));
        $this->assertEquals(4, $this->stock->cantidad($p->id, $otro->id));
        $this->assertSame($otro->id, $transferencia->deposito_destino_id);
    }

    public function test_usuario_sin_permiso_en_el_deposito(): void
    {
        $otro = Deposito::create(['codigo' => 'DEP02', 'nombre' => 'Otro depósito', 'activo' => true]);
        $operador = User::create([
            'empresa_id' => $this->empresa->id,
            'name' => 'Operador',
            'email' => 'operador@prueba.com',
            'password' => 'secreto123',
            'rol' => User::ROL_OPERADOR,
            'activo' => true,
            'todos_los_depositos' => false,
        ]);
        $operador->depositos()->sync([$this->deposito->id]);

        $this->expectException(ValidationException::class);
        $this->stock->ingresar($otro->id, [$this->linea($this->producto(), 1)], [], $operador);
    }

    public function test_unidad_sin_decimales_rechaza_fracciones(): void
    {
        $this->expectException(ValidationException::class);
        $this->stock->ingresar($this->deposito->id, [$this->linea($this->producto(), 1.5)], [], $this->admin);
    }

    public function test_un_servicio_no_mueve_stock(): void
    {
        $servicio = $this->producto(['tipo' => Producto::TIPO_SERVICIO, 'controla_stock' => false]);

        $this->expectException(ValidationException::class);
        $this->stock->ingresar($this->deposito->id, [$this->linea($servicio, 1)], [], $this->admin);
    }

    public function test_ajuste_por_conteo_registra_la_diferencia(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [$this->linea($p, 10)], [], $this->admin);

        $ajuste = $this->stock->ajustarAContado($this->deposito->id, [['producto_id' => $p->id, 'contado' => 7]], [], $this->admin);

        $this->assertEquals(7, $this->stock->cantidad($p->id));
        $this->assertEquals(-3, (float) $ajuste->items->first()->cantidad);
        $this->assertEquals(7, (float) $ajuste->items->first()->cantidad_contada);
        $this->assertSame(Movimiento::TIPO_AJUSTE, $ajuste->tipo);
    }

    public function test_ajuste_por_conteo_sin_diferencias_no_registra_nada(): void
    {
        $p = $this->producto();
        $this->stock->cargarInicial($this->deposito->id, [$this->linea($p, 10)], [], $this->admin);

        try {
            $this->stock->ajustarAContado($this->deposito->id, [['producto_id' => $p->id, 'contado' => 10]], [], $this->admin);
            $this->fail('Tendría que avisar que no hay diferencias.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no hay diferencias', $e->errors()['lineas'][0]);
        }

        $this->assertSame(1, Movimiento::count());
    }

    public function test_ajuste_por_conteo_en_una_ubicacion(): void
    {
        $this->deposito->update(['usa_ubicaciones' => true]);
        $estante = Ubicacion::create(['deposito_id' => $this->deposito->id, 'tipo' => 'posicion', 'codigo' => 'A-01-1']);
        $p = $this->producto();

        $this->stock->cargarInicial($this->deposito->id, [
            $this->linea($p, 5, ['ubicacion_id' => $estante->id]),
            $this->linea($p, 3),
        ], [], $this->admin);

        $this->stock->ajustarAContado($this->deposito->id, [
            ['producto_id' => $p->id, 'ubicacion_id' => $estante->id, 'contado' => 8],
        ], [], $this->admin);

        $this->assertEquals(8, Existencia::where('producto_id', $p->id)->where('ubicacion_id', $estante->id)->value('cantidad'));
        $this->assertEquals(11, $this->stock->cantidad($p->id));
    }

    public function test_el_movimiento_guarda_el_motivo(): void
    {
        $p = $this->producto();
        $rotura = Motivo::where('tipo', 'egreso')->where('nombre', 'Rotura')->firstOrFail();
        $this->stock->cargarInicial($this->deposito->id, [$this->linea($p, 3)], [], $this->admin);

        $salida = $this->stock->egresar($this->deposito->id, [$this->linea($p, 1)], ['motivo_id' => $rotura->id], $this->admin);

        $this->assertSame($rotura->id, $salida->motivo_id);
    }
}
