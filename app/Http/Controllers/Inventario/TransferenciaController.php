<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Producto;
use App\Models\Transferencia;
use App\Models\Ubicacion;
use App\Services\TransferenciaService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransferenciaController extends Controller
{
    public function __construct(protected TransferenciaService $servicio)
    {
    }

    public function index(Request $request)
    {
        $usuario = $request->user();
        $depositos = $usuario->depositosPermitidos();

        $filtros = [
            'estado' => array_key_exists((string) $request->query('estado'), Transferencia::ESTADOS) ? $request->query('estado') : null,
            'deposito_id' => $request->integer('deposito_id') ?: null,
            'q' => trim((string) $request->query('q')),
        ];

        $transferencias = Transferencia::query()
            ->with(['origen', 'destino', 'enviadaPor'])
            ->withCount('items')
            ->accesiblesPara($usuario)
            ->when($filtros['estado'], fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['deposito_id'], fn ($q, $id) => $q->where(fn ($w) => $w->where('deposito_origen_id', $id)->orWhere('deposito_destino_id', $id)))
            ->when($filtros['q'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['q'];
                $q->where(function ($w) use ($texto) {
                    $w->where('transportista', 'like', "%{$texto}%")->orWhere('observaciones', 'like', "%{$texto}%");
                    if (ctype_digit($texto)) {
                        $w->orWhere('numero', (int) $texto);
                    }
                });
            })
            ->orderByRaw("CASE WHEN estado = 'en_transito' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('inventario.transferencias', [
            'transferencias' => $transferencias,
            'filtros' => $filtros,
            'depositos' => $depositos,
            'estados' => Transferencia::ESTADOS,
            'pendientes' => Transferencia::pendientesPara($usuario)->count(),
        ]);
    }

    public function create(Request $request)
    {
        $origenes = $request->user()->depositosPermitidos();

        if ($origenes->isEmpty()) {
            return redirect()->route('inventario.transferencias.index')->with('error', 'No tenés depósitos asignados para enviar mercadería.');
        }

        $destinos = Deposito::activos()->orderByDesc('es_principal')->orderBy('nombre')->get();

        if ($destinos->count() < 2) {
            return redirect()->route('inventario.transferencias.index')->with('error', 'Necesitás al menos dos depósitos activos para transferir.');
        }

        return view('inventario.transferencia-form', [
            'origenes' => $origenes,
            'destinos' => $destinos,
            'origenInicial' => (int) old('deposito_origen_id', $origenes->firstWhere('es_principal', true)?->id ?? $origenes->first()->id),
            'ubicaciones' => $this->ubicacionesPorDeposito($origenes),
            'lineasPrevias' => $this->lineasPrevias($request->old('lineas', [])),
            'puedeRecibirAhora' => true,
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'deposito_origen_id' => ['required', 'integer'],
            'deposito_destino_id' => ['required', 'integer', 'different:deposito_origen_id'],
            'transportista' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1', 'max:500'],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.ubicacion_id' => ['nullable', 'integer'],
            'lineas.*.lote_id' => ['nullable', 'integer'],
            'lineas.*.series' => ['nullable', 'string', 'max:20000'],
        ], [
            'lineas.required' => 'Agregá al menos un producto.',
            'deposito_destino_id.different' => 'El depósito de destino tiene que ser distinto al de origen.',
        ], [
            'lineas.*.cantidad' => 'cantidad',
        ]);

        $usuario = $request->user();
        $recibirAhora = $request->boolean('recibir_ahora');

        if ($recibirAhora && ! $usuario->puedeOperarDeposito((int) $datos['deposito_destino_id'])) {
            throw ValidationException::withMessages(['recibir_ahora' => 'Para recibirla en el momento tenés que tener permiso en el depósito de destino.']);
        }

        $transferencia = $this->servicio->enviar(
            (int) $datos['deposito_origen_id'],
            (int) $datos['deposito_destino_id'],
            $datos['lineas'],
            ['transportista' => $datos['transportista'] ?? null, 'observaciones' => $datos['observaciones'] ?? null],
            $usuario,
            $recibirAhora
        );

        return redirect()->route('inventario.transferencias.show', $transferencia)
            ->with('success', $recibirAhora
                ? "Transferencia N° {$transferencia->numero_texto} registrada y recibida."
                : "Transferencia N° {$transferencia->numero_texto} enviada. Queda en tránsito hasta que la reciban en destino.");
    }

    public function show(Request $request, Transferencia $transferencia)
    {
        abort_unless($transferencia->puedeVerla($request->user()), 404);

        $transferencia->load([
            'items.producto.unidad',
            'items.lote',
            'items.ubicacionOrigen',
            'items.ubicacionDestino',
            'origen',
            'destino',
            'enviadaPor',
            'recibidaPor',
            'anuladaPor',
            'movimientos.motivo',
        ]);

        $usuario = $request->user();

        return view('inventario.transferencia', [
            'transferencia' => $transferencia,
            'puedeRecibir' => $transferencia->puedeRecibirla($usuario),
            'puedeAnular' => $transferencia->puedeAnularla($usuario),
            'empresa' => \App\Support\EmpresaActual::modelo(),
        ]);
    }

    public function recepcion(Request $request, Transferencia $transferencia)
    {
        abort_unless($transferencia->puedeRecibirla($request->user()), 403, 'No podés recibir esta transferencia.');

        $transferencia->load(['items.producto.unidad', 'items.lote', 'items.ubicacionOrigen', 'origen', 'destino', 'enviadaPor']);

        $ubicaciones = $transferencia->destino->usa_ubicaciones
            ? Ubicacion::activas()->where('deposito_id', $transferencia->deposito_destino_id)->orderBy('codigo')->get()
            : collect();

        return view('inventario.transferencia-recibir', [
            'transferencia' => $transferencia,
            'ubicaciones' => $ubicaciones,
            'opcionesDiferencias' => Transferencia::DIFERENCIAS,
        ]);
    }

    public function recibir(Request $request, Transferencia $transferencia)
    {
        abort_unless($transferencia->puedeVerla($request->user()), 404);

        $datos = $request->validate([
            'recepcion' => ['required', 'array'],
            'recepcion.*.cantidad_recibida' => ['nullable', 'numeric', 'min:0'],
            'recepcion.*.ubicacion_destino_id' => ['nullable', 'integer'],
            'recepcion.*.series_recibidas' => ['nullable', 'array'],
            'recepcion.*.series_recibidas.*' => ['string', 'max:100'],
            'destino_diferencias' => ['nullable', 'in:' . implode(',', array_keys(Transferencia::DIFERENCIAS))],
            'observaciones_recepcion' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'recepcion.*.cantidad_recibida' => 'cantidad recibida',
        ]);

        $transferencia = $this->servicio->recibir($transferencia, $datos['recepcion'], [
            'destino_diferencias' => $datos['destino_diferencias'] ?? null,
            'observaciones_recepcion' => $datos['observaciones_recepcion'] ?? null,
        ], $request->user());

        return redirect()->route('inventario.transferencias.show', $transferencia)
            ->with($transferencia->estado === Transferencia::RECIBIDA ? 'success' : 'warning',
                $transferencia->estado === Transferencia::RECIBIDA
                    ? 'Transferencia recibida completa.'
                    : 'Transferencia recibida con diferencias. Lo que faltó quedó registrado.');
    }

    public function anular(Request $request, Transferencia $transferencia)
    {
        abort_unless($transferencia->puedeAnularla($request->user()), 403, 'No podés anular esta transferencia.');

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']], ['motivo.required' => 'Indicá el motivo.']);

        try {
            $this->servicio->anular($transferencia, $request->user(), $datos['motivo']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()->route('inventario.transferencias.show', $transferencia)
            ->with('success', 'Transferencia anulada: la mercadería volvió al depósito de origen.');
    }

    /* ---------------------------------------------------------- */

    protected function ubicacionesPorDeposito($depositos): array
    {
        return Ubicacion::activas()
            ->whereIn('deposito_id', $depositos->where('usa_ubicaciones', true)->pluck('id'))
            ->orderBy('codigo')
            ->get(['id', 'deposito_id', 'codigo', 'nombre'])
            ->groupBy('deposito_id')
            ->map(fn ($grupo) => $grupo->map(fn ($u) => ['id' => $u->id, 'etiqueta' => $u->etiqueta])->values())
            ->all();
    }

    protected function lineasPrevias(array $lineas): array
    {
        if (! $lineas) {
            return [];
        }

        $productos = Producto::with('unidad')->whereIn('id', collect($lineas)->pluck('producto_id')->filter())->get()->keyBy('id');

        return collect($lineas)->map(function ($linea) use ($productos) {
            $producto = $productos->get((int) ($linea['producto_id'] ?? 0));

            return $producto ? [
                'producto' => [
                    'id' => $producto->id,
                    'sku' => $producto->sku,
                    'nombre' => $producto->nombre,
                    'unidad' => $producto->unidad?->abreviatura,
                    'permite_decimales' => (bool) $producto->unidad?->permite_decimales,
                    'usa_lotes' => $producto->usa_lotes,
                    'usa_series' => $producto->usa_series,
                ],
                'cantidad' => $linea['cantidad'] ?? '',
                'ubicacion_id' => $linea['ubicacion_id'] ?? '',
                'lote_id' => $linea['lote_id'] ?? '',
                'series' => $linea['series'] ?? '',
            ] : null;
        })->filter()->values()->all();
    }
}
