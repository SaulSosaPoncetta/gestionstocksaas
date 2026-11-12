<?php

namespace App\Http\Controllers;

use App\Models\Deposito;
use App\Models\Movimiento;
use App\Models\Producto;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $empresa = EmpresaActual::modelo();
        $integraciones = $empresa->integraciones()->activas()->get();
        $permitidos = $request->user()->depositosPermitidos()->pluck('id')->all();

        $bajoMinimo = Producto::query()
            ->stockeables()
            ->activos()
            ->where('stock_minimo', '>', 0)
            ->withSum(['existencias as stock' => fn ($q) => $q->whereIn('deposito_id', $permitidos)], 'cantidad')
            ->havingRaw('COALESCE(stock, 0) < productos.stock_minimo')
            ->toBase()
            ->getCountForPagination();

        $movimientosHoy = Movimiento::query()
            ->accesiblesPara($request->user())
            ->where('fecha', '>=', today())
            ->count();

        $indicadores = [
            [
                'titulo' => 'Productos con stock',
                'valor' => Producto::stockeables()->activos()->count(),
                'icono' => 'bi-box-seam',
                'color' => 'primary',
                'url' => route('inventario.stock.index'),
            ],
            [
                'titulo' => 'Depósitos',
                'valor' => Deposito::activos()->count(),
                'icono' => 'bi-buildings',
                'color' => 'info',
                'url' => $request->user()->tieneRol('admin', 'encargado') ? route('inventario.depositos.index') : null,
            ],
            [
                'titulo' => 'Bajo stock mínimo',
                'valor' => $bajoMinimo,
                'icono' => 'bi-exclamation-triangle',
                'color' => 'warning',
                'url' => route('inventario.stock.index', ['estado' => 'bajo_minimo']),
            ],
            [
                'titulo' => 'Movimientos hoy',
                'valor' => $movimientosHoy,
                'icono' => 'bi-arrow-left-right',
                'color' => 'success',
                'url' => route('inventario.movimientos.index', ['desde' => today()->toDateString()]),
            ],
        ];

        return view('dashboard', compact('empresa', 'integraciones', 'indicadores'));
    }
}
