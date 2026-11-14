<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\TomaInventario;
use App\Models\Ubicacion;
use App\Services\TomaInventarioService;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TomaInventarioController extends Controller
{
    public function __construct(protected TomaInventarioService $tomas)
    {
    }

    public function index(Request $request)
    {
        $depositos = $request->user()->depositosPermitidos();
        $estado = $request->query('estado', 'todas');

        $tomas = TomaInventario::query()
            ->with(['deposito', 'ubicacion', 'categoria', 'usuario'])
            ->withCount([
                'items',
                'items as contados_count' => fn ($q) => $q->whereNotNull('contado'),
            ])
            ->whereIn('deposito_id', $depositos->pluck('id'))
            ->when(array_key_exists($estado, TomaInventario::ESTADOS), fn ($q) => $q->where('estado', $estado))
            ->when($request->integer('deposito_id'), fn ($q, $id) => $q->where('deposito_id', $id))
            ->orderByRaw("CASE WHEN estado = 'en_curso' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('inventario.tomas', [
            'tomas' => $tomas,
            'depositos' => $depositos,
            'estados' => TomaInventario::ESTADOS,
            'filtros' => ['estado' => $estado, 'deposito_id' => $request->integer('deposito_id') ?: null],
        ]);
    }

    public function create(Request $request)
    {
        $depositos = $request->user()->depositosPermitidos();

        if ($depositos->isEmpty()) {
            return redirect()->route('inventario.tomas.index')->with('error', 'No tenés depósitos asignados.');
        }

        $ubicaciones = [];
        foreach ($depositos->where('usa_ubicaciones', true) as $deposito) {
            $ubicaciones[$deposito->id] = Ubicacion::arbol($deposito->id, true)
                ->map(fn ($u) => ['id' => $u->id, 'etiqueta' => str_repeat('— ', $u->nivel) . $u->etiqueta])
                ->values();
        }

        return view('inventario.toma-form', [
            'depositos' => $depositos,
            'depositoInicial' => (int) old('deposito_id', $request->integer('deposito_id') ?: ($depositos->firstWhere('es_principal', true)?->id ?? $depositos->first()->id)),
            'ubicaciones' => $ubicaciones,
            'categorias' => Categoria::arbol(true),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'deposito_id' => ['required', 'integer'],
            'ubicacion_id' => ['nullable', 'integer'],
            'categoria_id' => ['nullable', 'integer'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $toma = $this->tomas->iniciar($datos + [
            'congela' => $request->boolean('congela'),
            'a_ciegas' => $request->boolean('a_ciegas'),
            'incluir_sin_stock' => $request->boolean('incluir_sin_stock'),
        ], $request->user());

        $mensaje = "Toma N° {$toma->numero_texto} iniciada con {$toma->items->count()} renglones."
            . ($toma->congela ? ' El depósito quedó congelado: no admite movimientos hasta cerrarla.' : '');

        return redirect()->route('inventario.tomas.show', $toma)->with('success', $mensaje);
    }

    public function show(Request $request, TomaInventario $toma)
    {
        $usuario = $request->user();
        abort_unless($usuario->puedeOperarDeposito($toma->deposito_id), 404);

        $toma->load([
            'deposito', 'ubicacion', 'categoria', 'usuario', 'usuarioCierre', 'movimiento',
            'items.producto.unidad', 'items.producto.codigos', 'items.ubicacion', 'items.lote', 'items.usuarioConteo',
        ]);

        $items = $toma->items
            ->sortBy(fn ($i) => sprintf('%s|%s|%s', $i->ubicacion?->codigo ?? '', mb_strtolower($i->producto?->nombre ?? ''), $i->lote_texto ?? ''))
            ->values();

        return view('inventario.toma', [
            'toma' => $toma,
            'items' => $items,
            'resumen' => TomaInventarioService::resumen($items),
            'muestraTeorico' => $toma->muestraTeoricoA($usuario),
            'puedeAdministrar' => $usuario->tieneRol('admin', 'encargado'),
            'ubicaciones' => $toma->deposito?->usa_ubicaciones
                ? Ubicacion::activas()->where('deposito_id', $toma->deposito_id)->orderBy('codigo')->get(['id', 'codigo', 'nombre'])
                : collect(),
        ]);
    }

    /** Guarda lo contado (solo los renglones que se tocaron). */
    public function conteo(Request $request, TomaInventario $toma)
    {
        abort_unless($request->user()->puedeOperarDeposito($toma->deposito_id), 404);

        $datos = $request->validate([
            'conteos' => ['nullable', 'array', 'max:2000'],
            'conteos.*.contado' => ['nullable', 'string', 'max:30'],
            'conteos.*.series' => ['nullable', 'string', 'max:50000'],
            'conteos.*.limpiar' => ['nullable', 'boolean'],
        ]);

        try {
            $guardados = $this->tomas->registrarConteo($toma, $datos['conteos'] ?? [], $request->user());
        } catch (ValidationException $e) {
            if ($request->wantsJson()) {
                throw $e;
            }

            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        if ($request->wantsJson()) {
            return response()->json(['guardados' => $guardados]);
        }

        return redirect()->route('inventario.tomas.show', $toma)->with('success', $guardados ? "Se guardaron {$guardados} conteos." : 'No había cambios para guardar.');
    }

    /** Agrega algo que apareció y no estaba en la lista. */
    public function agregar(Request $request, TomaInventario $toma)
    {
        abort_unless($request->user()->puedeOperarDeposito($toma->deposito_id), 404);

        $datos = $request->validate([
            'producto_id' => ['required', 'integer'],
            'ubicacion_id' => ['nullable', 'integer'],
            'lote_id' => ['nullable', 'integer'],
            'lote_codigo' => ['nullable', 'string', 'max:60'],
            'lote_vencimiento' => ['nullable', 'date'],
            'contado' => ['nullable', 'string', 'max:30'],
            'series' => ['nullable', 'string', 'max:50000'],
        ], ['producto_id.required' => 'Elegí el producto.']);

        try {
            $item = $this->tomas->agregarRenglon($toma, $datos, $request->user());
        } catch (ValidationException $e) {
            return back()->withInput()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()->to(route('inventario.tomas.show', $toma) . '#item-' . $item->id)->with('success', 'Renglón agregado y contado.');
    }

    public function cerrar(Request $request, TomaInventario $toma)
    {
        abort_unless($request->user()->tieneRol('admin', 'encargado') && $request->user()->puedeOperarDeposito($toma->deposito_id), 403);

        $datos = $request->validate([
            'no_contados' => ['required', 'in:ignorar,cero'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $toma = $this->tomas->cerrar($toma, $datos['no_contados'], $request->user(), $datos['observaciones'] ?? null);
        } catch (ValidationException $e) {
            return back()->with('error', 'No se pudo cerrar: ' . collect($e->errors())->flatten()->implode(' '));
        }

        $mensaje = $toma->movimiento_id
            ? "Toma cerrada: se ajustaron {$toma->lineas_con_diferencia} renglones con diferencia."
            : 'Toma cerrada: el conteo coincidió con el sistema, no hubo nada que ajustar.';

        return redirect()->route('inventario.tomas.show', $toma)->with('success', $mensaje);
    }

    public function cancelar(Request $request, TomaInventario $toma)
    {
        abort_unless($request->user()->tieneRol('admin', 'encargado') && $request->user()->puedeOperarDeposito($toma->deposito_id), 403);

        $datos = $request->validate(['motivo' => ['required', 'string', 'max:255']], ['motivo.required' => 'Indicá el motivo.']);

        $this->tomas->cancelar($toma, $datos['motivo'], $request->user());

        return redirect()->route('inventario.tomas.show', $toma)->with('success', 'Toma cancelada: no se ajustó nada' . ($toma->congela ? ' y el depósito volvió a operar.' : '.'));
    }

    /** Planilla imprimible para contar en papel. */
    public function planilla(Request $request, TomaInventario $toma)
    {
        $usuario = $request->user();
        abort_unless($usuario->puedeOperarDeposito($toma->deposito_id), 404);

        $toma->load(['deposito', 'ubicacion', 'categoria', 'items.producto.unidad', 'items.ubicacion', 'items.lote']);

        return view('inventario.toma-planilla', [
            'toma' => $toma,
            'empresa' => EmpresaActual::modelo(),
            'items' => $toma->items->sortBy(fn ($i) => sprintf('%s|%s', $i->ubicacion?->codigo ?? '', mb_strtolower($i->producto?->nombre ?? '')))->values(),
            'muestraTeorico' => $toma->muestraTeoricoA($usuario) && ! $request->boolean('ciega'),
        ]);
    }
}
