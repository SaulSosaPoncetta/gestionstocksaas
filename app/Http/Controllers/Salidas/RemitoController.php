<?php

namespace App\Http\Controllers\Salidas;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Motivo;
use App\Models\Producto;
use App\Models\Remito;
use App\Models\Reserva;
use App\Models\Ubicacion;
use App\Services\SalidaService;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RemitoController extends Controller
{
    public function __construct(protected SalidaService $salidas)
    {
    }

    public function index(Request $request)
    {
        $depositos = $request->user()->depositosPermitidos();

        $remitos = Remito::query()
            ->with(['cliente', 'deposito', 'reserva'])
            ->withCount('items')
            ->whereIn('deposito_id', $depositos->pluck('id'))
            ->when($request->integer('deposito_id'), fn ($q, $id) => $q->where('deposito_id', $id))
            ->when(ctype_digit((string) $request->query('q')), fn ($q) => $q->where('numero', (int) $request->query('q')))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('salidas.remitos', [
            'remitos' => $remitos,
            'depositos' => $depositos,
            'filtros' => ['deposito_id' => $request->integer('deposito_id') ?: null, 'q' => $request->query('q')],
        ]);
    }

    /** Remito nuevo: directo, o para entregar una reserva (?reserva=ID). */
    public function create(Request $request)
    {
        $usuario = $request->user();
        $depositos = $usuario->depositosPermitidos();
        $reserva = null;

        if ($request->integer('reserva')) {
            $reserva = Reserva::with(['items.producto.unidad', 'items.lote', 'cliente', 'deposito'])->findOrFail($request->integer('reserva'));
            abort_unless($usuario->puedeOperarDeposito($reserva->deposito_id), 404);

            if (! $reserva->estaActiva()) {
                return redirect()->route('salidas.reservas.show', $reserva)->with('warning', 'La reserva ya no está activa.');
            }
        }

        if ($depositos->isEmpty()) {
            return redirect()->route('salidas.remitos.index')->with('error', 'No tenés depósitos asignados.');
        }

        $previas = $request->old('lineas');
        if ($previas === null && $reserva) {
            $previas = $reserva->items->filter(fn ($i) => $i->pendiente > 0)->map(fn ($i) => [
                'producto_id' => $i->producto_id,
                'cantidad' => $i->pendiente,
                'reserva_item_id' => $i->id,
                'lote_id' => $i->lote_id,
                'pendiente' => $i->pendiente,
                'lote_texto' => $i->lote?->codigo,
            ])->values()->all();
        }

        return view('salidas.remito-form', [
            'reserva' => $reserva,
            'depositos' => $depositos,
            'depositoInicial' => (int) old('deposito_id', $reserva?->deposito_id ?? $depositos->firstWhere('es_principal', true)?->id ?? $depositos->first()->id),
            'clientes' => Cliente::activos()->orderBy('razon_social')->limit(1000)->get(),
            'motivos' => Motivo::activos()->deTipo('egreso')->get(),
            'ubicaciones' => $this->ubicacionesPorDeposito($depositos),
            'lineasPrevias' => $this->lineasPrevias($previas ?? []),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'reserva_id' => ['nullable', 'integer'],
            'deposito_id' => ['required_without:reserva_id', 'nullable', 'integer'],
            'cliente_id' => ['nullable', 'integer'],
            'motivo_id' => ['nullable', 'integer'],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'direccion_entrega' => ['nullable', 'string', 'max:255'],
            'transportista' => ['nullable', 'string', 'max:150'],
            'comprobante_venta' => ['nullable', 'string', 'max:60'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1', 'max:500'],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.cantidad' => ['required', 'numeric', 'min:0'],
            'lineas.*.reserva_item_id' => ['nullable', 'integer'],
            'lineas.*.lote_id' => ['nullable', 'integer'],
            'lineas.*.ubicacion_id' => ['nullable', 'integer'],
            'lineas.*.series' => ['nullable', 'string', 'max:20000'],
        ], ['lineas.required' => 'Agregá al menos un producto.'], ['lineas.*.cantidad' => 'cantidad']);

        $reserva = ! empty($datos['reserva_id']) ? Reserva::findOrFail($datos['reserva_id']) : null;

        if ($reserva) {
            abort_unless($request->user()->puedeOperarDeposito($reserva->deposito_id), 404);
        }

        $motivoId = ! empty($datos['motivo_id']) && Motivo::where('tipo', 'egreso')->whereKey($datos['motivo_id'])->exists()
            ? (int) $datos['motivo_id'] : null;

        $remito = $this->salidas->emitirRemito([
            'cliente_id' => ! empty($datos['cliente_id']) && Cliente::whereKey($datos['cliente_id'])->exists() ? (int) $datos['cliente_id'] : null,
            'deposito_id' => $datos['deposito_id'] ?? null,
            'motivo_id' => $motivoId,
            'fecha' => $datos['fecha'],
            'direccion_entrega' => $datos['direccion_entrega'] ?? null,
            'transportista' => $datos['transportista'] ?? null,
            'comprobante_venta' => $datos['comprobante_venta'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
        ], $datos['lineas'], $reserva, $request->user());

        return redirect()->route('salidas.remitos.show', $remito)->with('success', "Remito N° {$remito->numero_texto} emitido. La mercadería ya salió del stock.");
    }

    public function show(Request $request, Remito $remito)
    {
        abort_unless($request->user()->puedeOperarDeposito($remito->deposito_id), 404);

        $remito->load([
            'items.producto.unidad',
            'cliente',
            'deposito',
            'reserva',
            'usuario',
            'movimiento.motivo',
            'movimiento.items.lote',
            'movimiento.items.series',
            'movimiento.items.ubicacion',
        ]);

        return view('salidas.remito', ['remito' => $remito, 'empresa' => EmpresaActual::modelo()]);
    }

    public function anular(Request $request, Remito $remito)
    {
        abort_unless($request->user()->tieneRol('admin', 'encargado') && $request->user()->puedeOperarDeposito($remito->deposito_id), 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']], ['motivo.required' => 'Indicá el motivo.']);

        try {
            $this->salidas->anularRemito($remito, $request->user(), $datos['motivo']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()->route('salidas.remitos.show', $remito)->with('success', 'Remito anulado: la mercadería volvió al stock.');
    }

    /* ---------------------------------------------------------- */

    protected function ubicacionesPorDeposito($depositos): array
    {
        return Ubicacion::activas()
            ->whereIn('deposito_id', $depositos->where('usa_ubicaciones', true)->pluck('id'))
            ->orderBy('codigo')
            ->get(['id', 'deposito_id', 'codigo', 'nombre'])
            ->groupBy('deposito_id')
            ->map(fn ($g) => $g->map(fn ($u) => ['id' => $u->id, 'etiqueta' => $u->etiqueta])->values())
            ->all();
    }

    protected function lineasPrevias(array $lineas): array
    {
        if (! $lineas) {
            return [];
        }

        $productos = Producto::with('unidad')->whereIn('id', collect($lineas)->pluck('producto_id')->filter())->get()->keyBy('id');

        return collect($lineas)->map(function ($linea) use ($productos) {
            $p = $productos->get((int) ($linea['producto_id'] ?? 0));

            return $p ? [
                'producto' => [
                    'id' => $p->id, 'sku' => $p->sku, 'nombre' => $p->nombre, 'unidad' => $p->unidad?->abreviatura,
                    'permite_decimales' => (bool) $p->unidad?->permite_decimales, 'usa_lotes' => $p->usa_lotes, 'usa_series' => $p->usa_series,
                ],
                'cantidad' => $linea['cantidad'] ?? '',
                'reserva_item_id' => $linea['reserva_item_id'] ?? '',
                'pendiente' => $linea['pendiente'] ?? null,
                'lote_id' => $linea['lote_id'] ?? '',
                'lote_texto' => $linea['lote_texto'] ?? null,
                'ubicacion_id' => $linea['ubicacion_id'] ?? '',
                'series' => $linea['series'] ?? '',
            ] : null;
        })->filter()->values()->all();
    }
}
