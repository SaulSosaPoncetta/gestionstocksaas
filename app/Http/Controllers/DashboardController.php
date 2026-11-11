<?php

namespace App\Http\Controllers;

use App\Support\EmpresaActual;

class DashboardController extends Controller
{
    public function index()
    {
        $empresa = EmpresaActual::modelo();
        $integraciones = $empresa->integraciones()->activas()->get();

        // Los indicadores reales se completan cuando existan productos, depósitos y movimientos
        $indicadores = [
            ['titulo' => 'Productos', 'valor' => 0, 'icono' => 'bi-box-seam', 'color' => 'primary'],
            ['titulo' => 'Depósitos', 'valor' => 0, 'icono' => 'bi-building', 'color' => 'info'],
            ['titulo' => 'Bajo stock mínimo', 'valor' => 0, 'icono' => 'bi-exclamation-triangle', 'color' => 'warning'],
            ['titulo' => 'Movimientos hoy', 'valor' => 0, 'icono' => 'bi-arrow-left-right', 'color' => 'success'],
        ];

        return view('dashboard', compact('empresa', 'integraciones', 'indicadores'));
    }
}
