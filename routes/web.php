<?php

use App\Http\Controllers\Admin\EmpresaAdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Compras\OrdenCompraController;
use App\Http\Controllers\Compras\ProveedorController;
use App\Http\Controllers\Compras\RecepcionCompraController;
use App\Http\Controllers\Compras\ReposicionController;
use App\Http\Controllers\Catalogo\CategoriaController;
use App\Http\Controllers\Catalogo\MarcaController;
use App\Http\Controllers\Catalogo\ProductoController;
use App\Http\Controllers\Catalogo\UnidadMedidaController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Configuracion\IntegracionController;
use App\Http\Controllers\Configuracion\MotivoController;
use App\Http\Controllers\Configuracion\UsuarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Salidas\ClienteController;
use App\Http\Controllers\Salidas\RemitoController;
use App\Http\Controllers\Salidas\ReservaController;
use App\Http\Controllers\Inventario\DepositoController;
use App\Http\Controllers\Inventario\MovimientoController;
use App\Http\Controllers\Inventario\SerieController;
use App\Http\Controllers\Inventario\StockController;
use App\Http\Controllers\Inventario\TomaInventarioController;
use App\Http\Controllers\Inventario\TransferenciaController;
use App\Http\Controllers\Inventario\UbicacionController;
use App\Http\Controllers\Inventario\VencimientoController;
use Illuminate\Support\Facades\Route;

/* ---------- Invitados ---------- */
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.post');
});

/* ---------- Usuarios logueados ---------- */
Route::middleware('auth')->group(function () {

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    /* ----- Superadmin (MiGestión) ----- */
    Route::middleware('rol:superadmin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/empresas', [EmpresaAdminController::class, 'index'])->name('empresas.index');
        Route::post('/empresas', [EmpresaAdminController::class, 'store'])->name('empresas.store');
        Route::post('/empresas/salir', [EmpresaAdminController::class, 'salir'])->name('empresas.salir');
        Route::post('/empresas/{empresa}/entrar', [EmpresaAdminController::class, 'entrar'])->name('empresas.entrar');
        Route::patch('/empresas/{empresa}/modulos', [EmpresaAdminController::class, 'modulos'])->name('empresas.modulos');
    });

    /* ----- Dentro de una empresa ----- */
    Route::middleware('empresa.activa')->group(function () {

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        /* Productos: todos los roles consultan */
        Route::get('/catalogo/productos', [ProductoController::class, 'index'])->name('catalogo.productos.index');
        Route::get('/catalogo/productos/buscar', [ProductoController::class, 'buscar'])->name('catalogo.productos.buscar');
        Route::get('/catalogo/productos/stock', [ProductoController::class, 'stock'])->name('catalogo.productos.stock');

        /* Stock, kardex y movimientos */
        Route::prefix('inventario')->name('inventario.')->group(function () {
            $tiposManuales = array_keys(MovimientoController::TIPOS_MANUALES);

            Route::get('/stock', [StockController::class, 'index'])->name('stock.index');
            Route::get('/kardex/{producto}', [StockController::class, 'kardex'])->name('kardex');
            Route::get('/vencimientos', [VencimientoController::class, 'index'])->name('vencimientos.index');
            Route::get('/series', [SerieController::class, 'index'])->name('series.index');
            Route::get('/series/{serie}', [SerieController::class, 'show'])->name('series.show');

            Route::get('/movimientos', [MovimientoController::class, 'index'])->name('movimientos.index');
            Route::get('/movimientos/{movimiento}', [MovimientoController::class, 'show'])->whereNumber('movimiento')->name('movimientos.show');

            // Cada tipo define qué roles lo pueden cargar (MovimientoController::TIPOS_MANUALES)
            Route::middleware('rol:admin,encargado,operador')->group(function () use ($tiposManuales) {
                Route::get('/movimientos/nuevo/{tipo}', [MovimientoController::class, 'create'])
                    ->whereIn('tipo', $tiposManuales)->name('movimientos.create');
                Route::post('/movimientos/nuevo/{tipo}', [MovimientoController::class, 'store'])
                    ->whereIn('tipo', $tiposManuales)->name('movimientos.store');
            });

            Route::post('/movimientos/{movimiento}/anular', [MovimientoController::class, 'anular'])
                ->middleware('rol:admin,encargado')->whereNumber('movimiento')->name('movimientos.anular');

            /* Transferencias: las ven y operan los usuarios de los depósitos de origen o destino */
            Route::get('/transferencias', [TransferenciaController::class, 'index'])->name('transferencias.index');
            Route::get('/transferencias/{transferencia}', [TransferenciaController::class, 'show'])->whereNumber('transferencia')->name('transferencias.show');

            Route::middleware('rol:admin,encargado,operador')->group(function () {
                Route::get('/transferencias/nueva', [TransferenciaController::class, 'create'])->name('transferencias.create');
                Route::post('/transferencias', [TransferenciaController::class, 'store'])->name('transferencias.store');
                Route::get('/transferencias/{transferencia}/recibir', [TransferenciaController::class, 'recepcion'])->whereNumber('transferencia')->name('transferencias.recepcion');
                Route::post('/transferencias/{transferencia}/recibir', [TransferenciaController::class, 'recibir'])->whereNumber('transferencia')->name('transferencias.recibir');
            });

            Route::post('/transferencias/{transferencia}/anular', [TransferenciaController::class, 'anular'])
                ->middleware('rol:admin,encargado')->whereNumber('transferencia')->name('transferencias.anular');

            /* Inventario físico: el encargado arma y cierra la toma; el operador cuenta */
            Route::middleware('rol:admin,encargado,operador')->group(function () {
                Route::get('/tomas', [TomaInventarioController::class, 'index'])->name('tomas.index');
                Route::get('/tomas/{toma}', [TomaInventarioController::class, 'show'])->whereNumber('toma')->name('tomas.show');
                Route::get('/tomas/{toma}/planilla', [TomaInventarioController::class, 'planilla'])->whereNumber('toma')->name('tomas.planilla');
                Route::post('/tomas/{toma}/conteo', [TomaInventarioController::class, 'conteo'])->whereNumber('toma')->name('tomas.conteo');
                Route::post('/tomas/{toma}/agregar', [TomaInventarioController::class, 'agregar'])->whereNumber('toma')->name('tomas.agregar');
            });

            Route::middleware('rol:admin,encargado')->group(function () {
                Route::get('/tomas/nueva', [TomaInventarioController::class, 'create'])->name('tomas.create');
                Route::post('/tomas', [TomaInventarioController::class, 'store'])->name('tomas.store');
                Route::post('/tomas/{toma}/cerrar', [TomaInventarioController::class, 'cerrar'])->whereNumber('toma')->name('tomas.cerrar');
                Route::post('/tomas/{toma}/cancelar', [TomaInventarioController::class, 'cancelar'])->whereNumber('toma')->name('tomas.cancelar');
            });
        });

        /* Abastecimiento (módulo que se habilita por plan) */
        Route::middleware('modulo:compras')->prefix('compras')->name('compras.')->group(function () {

            // Consulta de órdenes y recepción: también el operador del depósito
            Route::middleware('rol:admin,encargado,operador')->group(function () {
                Route::get('/ordenes', [OrdenCompraController::class, 'index'])->name('ordenes.index');
                Route::get('/ordenes/{orden}', [OrdenCompraController::class, 'show'])->whereNumber('orden')->name('ordenes.show');
                Route::get('/ordenes/{orden}/recibir', [RecepcionCompraController::class, 'create'])->whereNumber('orden')->name('recepciones.create');
                Route::post('/ordenes/{orden}/recibir', [RecepcionCompraController::class, 'store'])->whereNumber('orden')->name('recepciones.store');
                Route::get('/recepciones', [RecepcionCompraController::class, 'index'])->name('recepciones.index');
                Route::get('/recepciones/{recepcion}', [RecepcionCompraController::class, 'show'])->whereNumber('recepcion')->name('recepciones.show');
            });

            Route::middleware('rol:admin,encargado')->group(function () {
                Route::get('/reposicion', [ReposicionController::class, 'index'])->name('reposicion.index');
                Route::post('/reposicion', [ReposicionController::class, 'generar'])->name('reposicion.generar');

                Route::get('/productos/buscar', [OrdenCompraController::class, 'buscarProducto'])->name('productos.buscar');
                Route::get('/ordenes/nueva', [OrdenCompraController::class, 'create'])->name('ordenes.create');
                Route::post('/ordenes', [OrdenCompraController::class, 'store'])->name('ordenes.store');
                Route::get('/ordenes/{orden}/editar', [OrdenCompraController::class, 'edit'])->whereNumber('orden')->name('ordenes.edit');
                Route::put('/ordenes/{orden}', [OrdenCompraController::class, 'update'])->whereNumber('orden')->name('ordenes.update');
                Route::post('/ordenes/{orden}/enviar', [OrdenCompraController::class, 'enviar'])->whereNumber('orden')->name('ordenes.enviar');
                Route::post('/ordenes/{orden}/cancelar', [OrdenCompraController::class, 'cancelar'])->whereNumber('orden')->name('ordenes.cancelar');
                Route::post('/ordenes/{orden}/cerrar', [OrdenCompraController::class, 'cerrar'])->whereNumber('orden')->name('ordenes.cerrar');
                Route::post('/recepciones/{recepcion}/anular', [RecepcionCompraController::class, 'anular'])->whereNumber('recepcion')->name('recepciones.anular');

                Route::get('/proveedores', [ProveedorController::class, 'index'])->name('proveedores.index');
                Route::get('/proveedores/nuevo', [ProveedorController::class, 'create'])->name('proveedores.create');
                Route::post('/proveedores', [ProveedorController::class, 'store'])->name('proveedores.store');
                Route::get('/proveedores/{proveedor}', [ProveedorController::class, 'show'])->whereNumber('proveedor')->name('proveedores.show');
                Route::get('/proveedores/{proveedor}/editar', [ProveedorController::class, 'edit'])->whereNumber('proveedor')->name('proveedores.edit');
                Route::put('/proveedores/{proveedor}', [ProveedorController::class, 'update'])->whereNumber('proveedor')->name('proveedores.update');
                Route::delete('/proveedores/{proveedor}', [ProveedorController::class, 'destroy'])->whereNumber('proveedor')->name('proveedores.destroy');
                Route::post('/proveedores/{proveedor}/productos', [ProveedorController::class, 'guardarProducto'])->whereNumber('proveedor')->name('proveedores.productos.store');
                Route::delete('/proveedores/{proveedor}/productos/{vinculo}', [ProveedorController::class, 'quitarProducto'])->whereNumber('proveedor')->name('proveedores.productos.destroy');
            });
        });

        /* Salidas a clientes: remitos, reservas y clientes */
        Route::middleware('rol:admin,encargado,operador')->prefix('salidas')->name('salidas.')->group(function () {
            Route::get('/remitos', [RemitoController::class, 'index'])->name('remitos.index');
            Route::get('/remitos/nuevo', [RemitoController::class, 'create'])->name('remitos.create');
            Route::post('/remitos', [RemitoController::class, 'store'])->name('remitos.store');
            Route::get('/remitos/{remito}', [RemitoController::class, 'show'])->whereNumber('remito')->name('remitos.show');
            Route::post('/remitos/{remito}/anular', [RemitoController::class, 'anular'])->whereNumber('remito')
                ->middleware('rol:admin,encargado')->name('remitos.anular');

            Route::get('/reservas', [ReservaController::class, 'index'])->name('reservas.index');
            Route::get('/reservas/nueva', [ReservaController::class, 'create'])->name('reservas.create');
            Route::post('/reservas', [ReservaController::class, 'store'])->name('reservas.store');
            Route::get('/reservas/{reserva}', [ReservaController::class, 'show'])->whereNumber('reserva')->name('reservas.show');
            Route::post('/reservas/{reserva}/liberar', [ReservaController::class, 'liberar'])->whereNumber('reserva')->name('reservas.liberar');

            Route::get('/clientes', [ClienteController::class, 'index'])->name('clientes.index');
            Route::get('/clientes/nuevo', [ClienteController::class, 'create'])->name('clientes.create');
            Route::post('/clientes', [ClienteController::class, 'store'])->name('clientes.store');
            Route::get('/clientes/{cliente}', [ClienteController::class, 'show'])->whereNumber('cliente')->name('clientes.show');
            Route::get('/clientes/{cliente}/editar', [ClienteController::class, 'edit'])->whereNumber('cliente')->name('clientes.edit');
            Route::put('/clientes/{cliente}', [ClienteController::class, 'update'])->whereNumber('cliente')->name('clientes.update');
        });

        /* Depósitos y ubicaciones */
        Route::middleware('rol:admin,encargado')->prefix('inventario')->name('inventario.')->group(function () {

            Route::get('/depositos', [DepositoController::class, 'index'])->name('depositos.index');

            Route::middleware('rol:admin')->group(function () {
                Route::get('/depositos/crear', [DepositoController::class, 'create'])->name('depositos.create');
                Route::post('/depositos', [DepositoController::class, 'store'])->name('depositos.store');
                Route::get('/depositos/{deposito}/editar', [DepositoController::class, 'edit'])->name('depositos.edit');
                Route::put('/depositos/{deposito}', [DepositoController::class, 'update'])->name('depositos.update');
                Route::delete('/depositos/{deposito}', [DepositoController::class, 'destroy'])->name('depositos.destroy');
                Route::post('/depositos/{deposito}/principal', [DepositoController::class, 'principal'])->name('depositos.principal');
            });

            Route::get('/depositos/{deposito}/ubicaciones', [UbicacionController::class, 'index'])->name('ubicaciones.index');
            Route::post('/depositos/{deposito}/ubicaciones', [UbicacionController::class, 'store'])->name('ubicaciones.store');
            Route::post('/depositos/{deposito}/ubicaciones/generar', [UbicacionController::class, 'generar'])->name('ubicaciones.generar');
            Route::put('/depositos/{deposito}/ubicaciones/{ubicacion}', [UbicacionController::class, 'update'])->name('ubicaciones.update');
            Route::delete('/depositos/{deposito}/ubicaciones/{ubicacion}', [UbicacionController::class, 'destroy'])->name('ubicaciones.destroy');
        });

        /* Catálogo base: administradores y encargados */
        Route::middleware('rol:admin,encargado')->prefix('catalogo')->name('catalogo.')->group(function () {

            Route::get('/productos/crear', [ProductoController::class, 'create'])->name('productos.create');
            Route::post('/productos', [ProductoController::class, 'store'])->name('productos.store');
            Route::get('/productos/{producto}/editar', [ProductoController::class, 'edit'])->name('productos.edit');
            Route::put('/productos/{producto}', [ProductoController::class, 'update'])->name('productos.update');
            Route::delete('/productos/{producto}', [ProductoController::class, 'destroy'])->name('productos.destroy');

            Route::get('/categorias', [CategoriaController::class, 'index'])->name('categorias.index');
            Route::post('/categorias', [CategoriaController::class, 'store'])->name('categorias.store');
            Route::put('/categorias/{categoria}', [CategoriaController::class, 'update'])->name('categorias.update');
            Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy'])->name('categorias.destroy');

            Route::get('/marcas', [MarcaController::class, 'index'])->name('marcas.index');
            Route::post('/marcas', [MarcaController::class, 'store'])->name('marcas.store');
            Route::put('/marcas/{marca}', [MarcaController::class, 'update'])->name('marcas.update');
            Route::delete('/marcas/{marca}', [MarcaController::class, 'destroy'])->name('marcas.destroy');

            Route::get('/unidades', [UnidadMedidaController::class, 'index'])->name('unidades.index');
            Route::post('/unidades', [UnidadMedidaController::class, 'store'])->name('unidades.store');
            Route::put('/unidades/{unidad}', [UnidadMedidaController::class, 'update'])->name('unidades.update');
            Route::delete('/unidades/{unidad}', [UnidadMedidaController::class, 'destroy'])->name('unidades.destroy');
        });

        /* Configuración: solo administradores */
        Route::middleware('rol:admin')->prefix('configuracion')->name('configuracion.')->group(function () {

            Route::get('/empresa', [EmpresaController::class, 'edit'])->name('empresa.edit');
            Route::put('/empresa', [EmpresaController::class, 'update'])->name('empresa.update');

            Route::get('/motivos', [MotivoController::class, 'index'])->name('motivos.index');
            Route::post('/motivos', [MotivoController::class, 'store'])->name('motivos.store');
            Route::put('/motivos/{motivo}', [MotivoController::class, 'update'])->name('motivos.update');
            Route::delete('/motivos/{motivo}', [MotivoController::class, 'destroy'])->name('motivos.destroy');

            Route::get('/usuarios', [UsuarioController::class, 'index'])->name('usuarios.index');
            Route::get('/usuarios/crear', [UsuarioController::class, 'create'])->name('usuarios.create');
            Route::post('/usuarios', [UsuarioController::class, 'store'])->name('usuarios.store');
            Route::get('/usuarios/{usuario}/editar', [UsuarioController::class, 'edit'])->name('usuarios.edit');
            Route::put('/usuarios/{usuario}', [UsuarioController::class, 'update'])->name('usuarios.update');
            Route::patch('/usuarios/{usuario}/estado', [UsuarioController::class, 'toggle'])->name('usuarios.toggle');

            $sistemas = array_keys(config('ecosistema.sistemas', []));

            Route::get('/integraciones', [IntegracionController::class, 'index'])->name('integraciones.index');
            Route::get('/integraciones/{sistema}', [IntegracionController::class, 'edit'])
                ->whereIn('sistema', $sistemas)->name('integraciones.edit');
            Route::put('/integraciones/{sistema}', [IntegracionController::class, 'update'])
                ->whereIn('sistema', $sistemas)->name('integraciones.update');
            Route::post('/integraciones/{sistema}/token', [IntegracionController::class, 'token'])
                ->whereIn('sistema', $sistemas)->name('integraciones.token');
            Route::post('/integraciones/{sistema}/secreto', [IntegracionController::class, 'secreto'])
                ->whereIn('sistema', $sistemas)->name('integraciones.secreto');
        });
    });
});
