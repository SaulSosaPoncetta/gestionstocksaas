<?php

use App\Http\Controllers\Admin\EmpresaAdminController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Catalogo\CategoriaController;
use App\Http\Controllers\Catalogo\MarcaController;
use App\Http\Controllers\Catalogo\ProductoController;
use App\Http\Controllers\Catalogo\UnidadMedidaController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Configuracion\IntegracionController;
use App\Http\Controllers\Configuracion\MotivoController;
use App\Http\Controllers\Configuracion\UsuarioController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Inventario\DepositoController;
use App\Http\Controllers\Inventario\MovimientoController;
use App\Http\Controllers\Inventario\StockController;
use App\Http\Controllers\Inventario\UbicacionController;
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
