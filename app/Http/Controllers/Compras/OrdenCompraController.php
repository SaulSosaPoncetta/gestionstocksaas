<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Ubicacion;
use App\Services\CompraService;
use App\Support\EmpresaActual;
use App\Support\ProductoParaCompra;
use App\Services\ProductoService;
use App\Models\ProductoProveedor;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrdenCompraController extends Controller
{
    public function __construct(protected CompraService $compras)
    {
    }

    public function index(Request $request)
    {
        $usuario = $request->user();
        $depositos = $usuario->depositosPermitidos();

        $filtros = [
            'estado' => $request->query('estado', 'abiertas'),
            'proveedor_id' => $request->integer('proveedor_id') ?: null,
            'deposito_id' => $request->integer('deposito_id') ?: null,
            'q' => trim((string) $request->query('q')),
        ];

        $ordenes = OrdenCompra::query()
            ->with(['proveedor', 'deposito', 'items'])
            ->whereIn('deposito_id', $depositos->pluck('id'))
            ->when($filtros['estado'] === 'abiertas', fn ($q) => $q->whereIn('estado', OrdenCompra::ABIERTAS))
            ->when(array_key_exists($filtros['estado'], OrdenCompra::ESTADOS), fn ($q) => $q->where('estado', $filtros['estado']))
            ->when($filtros['proveedor_id'], fn ($q, $id) => $q->where('proveedor_id', $id))
            ->when($filtros['deposito_id'], fn ($q, $id) => $q->where('deposito_id', $id))
            ->when(ctype_digit($filtros['q']), fn ($q) => $q->where('numero', (int) $filtros['q']))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        return view('compras.ordenes', [
            'ordenes' => $ordenes,
            'filtros' => $filtros,
            'estados' => OrdenCompra::ESTADOS,
            'proveedores' => Proveedor::orderBy('razon_social')->get(),
            'depositos' => $depositos,
        ]);
    }

    public function create(Request $request)
    {
        return $this->formulario($request, new OrdenCompra([
            'fecha' => today(),
            'proveedor_id' => $request->integer('proveedor_id') ?: null,
        ]));
    }

    public function store(Request $request)
    {
        $orden = $this->compras->guardarOrden(null, $this->validar($request), $request->input('lineas', []), $request->user());

        return redirect()->route('compras.ordenes.show', $orden)->with('success', "Orden de compra N° {$orden->numero_texto} creada en borrador.");
    }

    public function show(Request $request, OrdenCompra $orden)
    {
        $this->autorizar($request, $orden);

        $orden->load([
            'items.producto.unidad',
            'items.presentacion',
            'proveedor',
            'deposito',
            'usuario',
            'recepciones.usuario',
        ]);

        // Código del proveedor de cada producto, para la orden impresa
        $codigos = $orden->proveedor
            ? \App\Models\ProductoProveedor::where('proveedor_id', $orden->proveedor_id)
                ->whereIn('producto_id', $orden->items->pluck('producto_id'))
                ->pluck('codigo_proveedor', 'producto_id')
            : collect();

        return view('compras.orden', [
            'orden' => $orden,
            'codigos' => $codigos,
            'empresa' => EmpresaActual::modelo(),
        ]);
    }

    public function edit(Request $request, OrdenCompra $orden)
    {
        $this->autorizar($request, $orden);

        if (! $orden->puedeEditarse()) {
            return redirect()->route('compras.ordenes.show', $orden)->with('warning', 'Esta orden ya no se puede modificar.');
        }

        return $this->formulario($request, $orden->load(['items.producto.unidad', 'items.producto.presentaciones', 'items.presentacion']));
    }

    public function update(Request $request, OrdenCompra $orden)
    {
        $this->autorizar($request, $orden);

        $this->compras->guardarOrden($orden, $this->validar($request), $request->input('lineas', []), $request->user());

        return redirect()->route('compras.ordenes.show', $orden)->with('success', 'Orden actualizada.');
    }

    public function enviar(Request $request, OrdenCompra $orden)
    {
        $this->autorizar($request, $orden);
        $this->compras->enviar($orden);

        return back()->with('success', 'Orden marcada como enviada al proveedor.');
    }

    public function cancelar(Request $request, OrdenCompra $orden)
    {
        $this->autorizar($request, $orden);

        return $this->accion(fn () => $this->compras->cancelar($orden, $request->input('motivo')), 'Orden cancelada.');
    }

    public function cerrar(Request $request, OrdenCompra $orden)
    {
        $this->autorizar($request, $orden);

        return $this->accion(fn () => $this->compras->cerrar($orden, $request->input('motivo')), 'Orden cerrada: lo pendiente ya no se espera.');
    }

    /**
     * Búsqueda de productos para la orden: por código de barras, SKU, nombre o código del proveedor.
     * GET /compras/productos/buscar?q=…&proveedor_id=…
     */
    public function buscarProducto(Request $request, ProductoService $productos)
    {
        $texto = trim((string) $request->query('q'));
        $proveedorId = $request->integer('proveedor_id') ?: null;

        if ($texto === '') {
            return response()->json(['exacto' => false, 'resultados' => []]);
        }

        $exacto = false;
        $encontrado = $productos->buscarPorCodigo($texto);

        if (! $encontrado && $proveedorId) {
            $vinculo = ProductoProveedor::where('proveedor_id', $proveedorId)->where('codigo_proveedor', $texto)->first();
            $encontrado = $vinculo?->producto ? ['producto' => $vinculo->producto, 'presentacion' => $vinculo->presentacion] : null;
        }

        if ($encontrado && $encontrado['producto']->manejaStock()) {
            $resultados = collect([$encontrado['producto']]);
            $presentacion = $encontrado['presentacion'];
            $exacto = true;
        } else {
            $presentacion = null;
            $resultados = Producto::query()
                ->activos()
                ->stockeables()
                ->where(fn ($q) => $q->buscar($texto)->when($proveedorId, fn ($w) => $w->orWhereHas(
                    'proveedores',
                    fn ($p) => $p->where('proveedor_id', $proveedorId)->where('codigo_proveedor', 'like', "%{$texto}%")
                )))
                ->orderBy('nombre')
                ->limit(15)
                ->get();
        }

        $resultados->load(['unidad', 'presentaciones', 'alicuota']);

        return response()->json([
            'exacto' => $exacto,
            'resultados' => $resultados->map(fn ($p) => ProductoParaCompra::datos($p, $proveedorId, $presentacion))->values(),
        ]);
    }

    /* ---------------------------------------------------------- */

    protected function formulario(Request $request, OrdenCompra $orden)
    {
        $depositos = $request->user()->depositosPermitidos();

        if ($depositos->isEmpty()) {
            return redirect()->route('compras.ordenes.index')->with('error', 'No tenés depósitos asignados.');
        }

        $lineas = $request->old('lineas');

        if ($lineas === null) {
            $lineas = $orden->items->map(fn ($item) => [
                'producto_id' => $item->producto_id,
                'presentacion_id' => $item->presentacion_id,
                'cantidad' => $item->presentacion_id ? (float) $item->cantidad_presentacion : (float) $item->cantidad,
                'costo_unitario' => $item->presentacion_id
                    ? round((float) $item->costo_unitario * (float) $item->presentacion?->cantidad, 4)
                    : (float) $item->costo_unitario,
            ])->all();
        }

        return view('compras.orden-form', [
            'orden' => $orden,
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->get(),
            'depositos' => $depositos,
            'depositoInicial' => (int) old('deposito_id', $orden->deposito_id ?? $depositos->firstWhere('es_principal', true)?->id ?? $depositos->first()->id),
            'lineasPrevias' => $this->lineasPrevias($lineas),
        ]);
    }

    protected function validar(Request $request): array
    {
        return $request->validate([
            'proveedor_id' => ['required', 'integer'],
            'deposito_id' => ['required', 'integer'],
            'fecha' => ['required', 'date'],
            'fecha_entrega' => ['nullable', 'date', 'after_or_equal:fecha'],
            'condiciones_pago' => ['nullable', 'string', 'max:150'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1', 'max:500'],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.presentacion_id' => ['nullable', 'integer'],
            'lineas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'lineas.*.costo_unitario' => ['required', 'numeric', 'min:0'],
        ], [
            'lineas.required' => 'Agregá al menos un producto.',
        ], [
            'lineas.*.cantidad' => 'cantidad',
            'lineas.*.costo_unitario' => 'costo',
        ]);
    }

    protected function lineasPrevias(array $lineas): array
    {
        if (! $lineas) {
            return [];
        }

        $productos = Producto::with(['unidad', 'presentaciones', 'alicuota'])
            ->whereIn('id', collect($lineas)->pluck('producto_id')->filter())
            ->get()
            ->keyBy('id');

        return collect($lineas)->map(function ($linea) use ($productos) {
            $producto = $productos->get((int) ($linea['producto_id'] ?? 0));

            return $producto ? [
                'producto' => ProductoParaCompra::datos($producto),
                'presentacion_id' => $linea['presentacion_id'] ?? '',
                'cantidad' => $linea['cantidad'] ?? '',
                'costo_unitario' => $linea['costo_unitario'] ?? '',
            ] : null;
        })->filter()->values()->all();
    }

    protected function autorizar(Request $request, OrdenCompra $orden): void
    {
        abort_unless($request->user()->puedeOperarDeposito($orden->deposito_id), 404);
    }

    protected function accion(callable $accion, string $mensaje)
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return back()->with('success', $mensaje);
    }
}
