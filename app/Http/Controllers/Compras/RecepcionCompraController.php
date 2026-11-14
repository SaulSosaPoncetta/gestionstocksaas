<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\OrdenCompra;
use App\Models\RecepcionCompra;
use App\Models\Ubicacion;
use App\Services\CompraService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RecepcionCompraController extends Controller
{
    public function __construct(protected CompraService $compras)
    {
    }

    public function index(Request $request)
    {
        $recepciones = RecepcionCompra::query()
            ->with(['orden', 'proveedor', 'deposito', 'usuario'])
            ->withCount('items')
            ->whereIn('deposito_id', $request->user()->depositosPermitidos()->pluck('id'))
            ->orderByDesc('id')
            ->paginate(30);

        return view('compras.recepciones', compact('recepciones'));
    }

    public function create(Request $request, OrdenCompra $orden)
    {
        abort_unless($request->user()->puedeOperarDeposito($orden->deposito_id), 404);

        if (! $orden->puedeRecibirse()) {
            return redirect()->route('compras.ordenes.show', $orden)->with('warning', 'Esta orden no tiene mercadería pendiente de recibir.');
        }

        $orden->load(['items.producto.unidad', 'items.presentacion', 'proveedor', 'deposito']);

        $ubicaciones = $orden->deposito->usa_ubicaciones
            ? Ubicacion::activas()->where('deposito_id', $orden->deposito_id)->orderBy('codigo')->get()
            : collect();

        return view('compras.recepcion-form', [
            'orden' => $orden,
            'ubicaciones' => $ubicaciones,
            'lotesConocidos' => \App\Http\Controllers\Inventario\MovimientoController::lotesConocidos(
                $orden->items->filter(fn ($i) => $i->producto?->usa_lotes)->pluck('producto_id')->all()
            ),
        ]);
    }

    public function store(Request $request, OrdenCompra $orden)
    {
        abort_unless($request->user()->puedeOperarDeposito($orden->deposito_id), 404);

        $datos = $request->validate([
            'remito' => ['nullable', 'string', 'max:60'],
            'factura' => ['nullable', 'string', 'max:60'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array'],
            'lineas.*.cantidad' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.ubicacion_id' => ['nullable', 'integer'],
            'lineas.*.lote_codigo' => ['nullable', 'string', 'max:60'],
            'lineas.*.lote_vencimiento' => ['nullable', 'date'],
            'lineas.*.series' => ['nullable', 'string', 'max:20000'],
        ], [], [
            'lineas.*.cantidad' => 'cantidad recibida',
            'lineas.*.costo_unitario' => 'costo',
        ]);

        $recepcion = $this->compras->recibir($orden, $datos['lineas'], $datos + [
            'actualizar_costos' => $request->boolean('actualizar_costos'),
            'actualizar_precios' => $request->boolean('actualizar_precios'),
        ], $request->user());

        return redirect()->route('compras.recepciones.show', $recepcion)
            ->with('success', "Recepción N° {$recepcion->numero_texto} registrada: el stock ya está en {$orden->deposito?->nombre}.");
    }

    public function show(Request $request, RecepcionCompra $recepcion)
    {
        abort_unless($request->user()->puedeOperarDeposito($recepcion->deposito_id), 404);

        $recepcion->load([
            'items.producto.unidad',
            'orden',
            'proveedor',
            'deposito',
            'usuario',
            'movimiento.items.lote',
            'movimiento.items.series',
            'movimiento.items.ubicacion',
        ]);

        return view('compras.recepcion', ['recepcion' => $recepcion]);
    }

    public function anular(Request $request, RecepcionCompra $recepcion)
    {
        abort_unless($request->user()->tieneRol('admin', 'encargado') && $request->user()->puedeOperarDeposito($recepcion->deposito_id), 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']], ['motivo.required' => 'Indicá el motivo.']);

        try {
            $this->compras->anularRecepcion($recepcion, $request->user(), $datos['motivo']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()->route('compras.recepciones.show', $recepcion)
            ->with('success', 'Recepción anulada: el stock salió y la orden vuelve a esperar esa mercadería.');
    }
}
