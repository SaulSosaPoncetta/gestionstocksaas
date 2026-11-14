<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Existencia;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;

class VencimientoController extends Controller
{
    public const ESTADOS = [
        'proximos' => 'Vencidos y por vencer',
        'vencidos' => 'Solo vencidos',
        'por_vencer' => 'Solo por vencer',
        'todos' => 'Todos los lotes con stock',
    ];

    /** Lotes con stock, ordenados por vencimiento. */
    public function index(Request $request)
    {
        $empresa = EmpresaActual::modelo();
        $depositos = $request->user()->depositosPermitidos();
        $depositoId = $request->integer('deposito_id') ?: null;

        if ($depositoId && ! $depositos->contains('id', $depositoId)) {
            $depositoId = null;
        }

        $dias = $request->integer('dias') ?: (int) $empresa->dias_aviso_vencimiento;
        $dias = max(1, min($dias, 3650));
        $estado = array_key_exists((string) $request->query('estado'), self::ESTADOS) ? $request->query('estado') : 'proximos';
        $texto = trim((string) $request->query('q'));

        $hoy = today()->toDateString();
        $limite = today()->addDays($dias)->toDateString();

        $filas = Existencia::query()
            ->join('lotes', 'lotes.id', '=', 'existencias.lote_id')
            ->join('productos', 'productos.id', '=', 'existencias.producto_id')
            ->whereIn('existencias.deposito_id', $depositoId ? [$depositoId] : $depositos->pluck('id'))
            ->where('existencias.cantidad', '>', 0)
            ->when($estado === 'vencidos', fn ($q) => $q->where('lotes.fecha_vencimiento', '<', $hoy))
            ->when($estado === 'por_vencer', fn ($q) => $q->whereBetween('lotes.fecha_vencimiento', [$hoy, $limite]))
            ->when($estado === 'proximos', fn ($q) => $q->where('lotes.fecha_vencimiento', '<=', $limite))
            ->when($texto !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('productos.nombre', 'like', "%{$texto}%")
                ->orWhere('productos.sku', 'like', "%{$texto}%")
                ->orWhere('lotes.codigo', 'like', "%{$texto}%")))
            ->groupBy('existencias.lote_id', 'existencias.deposito_id', 'existencias.producto_id')
            ->selectRaw('existencias.lote_id, existencias.deposito_id, existencias.producto_id, SUM(existencias.cantidad) AS cantidad')
            ->orderByRaw('MIN(lotes.fecha_vencimiento) IS NULL')
            ->orderByRaw('MIN(lotes.fecha_vencimiento)')
            ->with(['lote', 'deposito', 'producto.unidad'])
            ->paginate(50)
            ->withQueryString();

        return view('inventario.vencimientos', [
            'filas' => $filas,
            'depositos' => $depositos,
            'estados' => self::ESTADOS,
            'filtros' => ['deposito_id' => $depositoId, 'dias' => $dias, 'estado' => $estado, 'q' => $texto],
        ]);
    }
}
