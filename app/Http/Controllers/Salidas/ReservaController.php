<?php

namespace App\Http\Controllers\Salidas;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Producto;
use App\Models\Reserva;
use App\Services\SalidaService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReservaController extends Controller
{
    public function __construct(protected SalidaService $salidas)
    {
    }

    public function index(Request $request)
    {
        $depositos = $request->user()->depositosPermitidos();
        $estado = $request->query('estado', 'activa');

        $reservas = Reserva::query()
            ->with(['cliente', 'deposito', 'items'])
            ->whereIn('deposito_id', $depositos->pluck('id'))
            ->when(array_key_exists($estado, Reserva::ESTADOS), fn ($q) => $q->where('estado', $estado))
            ->when($request->integer('deposito_id'), fn ($q, $id) => $q->where('deposito_id', $id))
            ->when($request->integer('cliente_id'), fn ($q, $id) => $q->where('cliente_id', $id))
            ->orderByRaw('vence_at IS NULL')
            ->orderBy('vence_at')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('salidas.reservas', [
            'reservas' => $reservas,
            'depositos' => $depositos,
            'estados' => Reserva::ESTADOS,
            'filtros' => ['estado' => $estado, 'deposito_id' => $request->integer('deposito_id') ?: null],
        ]);
    }

    public function create(Request $request)
    {
        $depositos = $request->user()->depositosPermitidos();

        if ($depositos->isEmpty()) {
            return redirect()->route('salidas.reservas.index')->with('error', 'No tenés depósitos asignados.');
        }

        return view('salidas.reserva-form', [
            'depositos' => $depositos,
            'depositoInicial' => (int) old('deposito_id', $depositos->firstWhere('es_principal', true)?->id ?? $depositos->first()->id),
            'clientes' => Cliente::activos()->orderBy('razon_social')->limit(1000)->get(),
            'lineasPrevias' => $this->lineasPrevias($request->old('lineas', [])),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'deposito_id' => ['required', 'integer'],
            'cliente_id' => ['nullable', 'integer'],
            'vence_at' => ['nullable', 'date', 'after:now'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1', 'max:300'],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.lote_id' => ['nullable', 'integer'],
        ], ['lineas.required' => 'Agregá al menos un producto.', 'vence_at.after' => 'El vencimiento tiene que ser a futuro.'], ['lineas.*.cantidad' => 'cantidad']);

        $reserva = $this->salidas->reservar((int) $datos['deposito_id'], $datos['lineas'], [
            'cliente_id' => $this->clienteValido($datos['cliente_id'] ?? null),
            'vence_at' => $datos['vence_at'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
        ], $request->user());

        return redirect()->route('salidas.reservas.show', $reserva)->with('success', "Reserva N° {$reserva->numero_texto} creada: ese stock ya no está disponible para otras salidas.");
    }

    public function show(Request $request, Reserva $reserva)
    {
        abort_unless($request->user()->puedeOperarDeposito($reserva->deposito_id), 404);

        $reserva->load(['items.producto.unidad', 'items.lote', 'cliente', 'deposito', 'usuario', 'remitos']);

        return view('salidas.reserva', ['reserva' => $reserva]);
    }

    public function liberar(Request $request, Reserva $reserva)
    {
        abort_unless($request->user()->puedeOperarDeposito($reserva->deposito_id), 404);

        $datos = $request->validate([
            'motivo' => ['nullable', 'string', 'max:255'],
            'cantidades' => ['nullable', 'array'],
            'cantidades.*' => ['nullable', 'numeric', 'min:0'],
            'todo' => ['nullable'],
        ]);

        try {
            $this->salidas->liberar($reserva, $request->boolean('todo') ? null : ($datos['cantidades'] ?? []), $datos['motivo'] ?? null);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', 'Se liberó el stock reservado: ya está disponible.');
    }

    /* ---------------------------------------------------------- */

    protected function clienteValido(mixed $id): ?int
    {
        return $id && Cliente::whereKey((int) $id)->exists() ? (int) $id : null;
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
                    'permite_decimales' => (bool) $p->unidad?->permite_decimales, 'usa_lotes' => $p->usa_lotes, 'usa_series' => false,
                ],
                'cantidad' => $linea['cantidad'] ?? '',
                'lote_id' => $linea['lote_id'] ?? '',
            ] : null;
        })->filter()->values()->all();
    }
}
