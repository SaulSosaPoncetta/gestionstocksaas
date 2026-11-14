<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductoRequest;
use App\Models\AlicuotaIva;
use App\Models\Categoria;
use App\Models\Existencia;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCodigo;
use App\Models\UnidadMedida;
use App\Services\ProductoService;
use App\Services\StockService;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;

class ProductoController extends Controller
{
    public function __construct(protected ProductoService $servicio)
    {
    }

    public function index(Request $request)
    {
        $filtros = [
            'q' => trim((string) $request->query('q')),
            'categoria_id' => $request->integer('categoria_id') ?: null,
            'marca_id' => $request->integer('marca_id') ?: null,
            'tipo' => $request->query('tipo'),
            'estado' => $request->query('estado', 'activos'),
        ];

        $permitidos = $request->user()->depositosPermitidos()->pluck('id')->all();

        $consulta = Producto::query()
            ->with(['categoria', 'marca', 'alicuota', 'unidad', 'imagenPrincipal'])
            ->withCount('variantes')
            ->withSum(['existencias as stock_total' => fn ($q) => $q->whereIn('deposito_id', $permitidos)], 'cantidad');

        // Sin búsqueda se listan los productos "de primer nivel"; con búsqueda también las variantes
        if ($filtros['q'] === '') {
            $consulta->whereNull('padre_id');
        } else {
            $consulta->buscar($filtros['q']);
        }

        if ($filtros['categoria_id']) {
            $categoria = Categoria::find($filtros['categoria_id']);
            $ids = $categoria ? array_merge([$categoria->id], $categoria->descendientesIds()) : [0];
            $consulta->whereIn('categoria_id', $ids);
        }

        $consulta
            ->when($filtros['marca_id'], fn ($q, $id) => $q->where('marca_id', $id))
            ->when(isset(Producto::TIPOS[(string) $filtros['tipo']]), fn ($q) => $q->where('tipo', $filtros['tipo']))
            ->when($filtros['estado'] === 'activos', fn ($q) => $q->where('activo', true))
            ->when($filtros['estado'] === 'inactivos', fn ($q) => $q->where('activo', false));

        $productos = $consulta->orderBy('nombre')->paginate(25)->withQueryString();

        // Stock de los productos con variantes = suma de sus variantes
        $agrupadores = $productos->getCollection()->filter(fn ($p) => $p->esAgrupador())->pluck('id');
        $stockAgrupadores = $agrupadores->isEmpty() ? collect() : Existencia::query()
            ->join('productos', 'productos.id', '=', 'existencias.producto_id')
            ->whereIn('productos.padre_id', $agrupadores)
            ->whereIn('existencias.deposito_id', $permitidos)
            ->groupBy('productos.padre_id')
            ->selectRaw('productos.padre_id, SUM(existencias.cantidad) AS total')
            ->pluck('total', 'padre_id');

        return view('catalogo.productos', [
            'productos' => $productos,
            'stockAgrupadores' => $stockAgrupadores,
            'filtros' => $filtros,
            'categorias' => Categoria::arbol(),
            'marcas' => Marca::orderBy('nombre')->get(),
            'tipos' => Producto::TIPOS,
            'puedeEditar' => $request->user()->tieneRol('admin', 'encargado'),
        ]);
    }

    public function create(Request $request)
    {
        $tipo = $request->query('tipo', Producto::TIPO_SIMPLE);

        if (! in_array($tipo, [Producto::TIPO_SIMPLE, Producto::TIPO_CON_VARIANTES, Producto::TIPO_SERVICIO], true)) {
            $tipo = Producto::TIPO_SIMPLE;
        }

        $producto = new Producto([
            'tipo' => $tipo,
            'unidad_medida_id' => UnidadMedida::where('abreviatura', 'un')->value('id'),
            'alicuota_iva_id' => AlicuotaIva::predeterminada()?->id,
            'controla_stock' => $tipo !== Producto::TIPO_SERVICIO,
            'costo' => 0,
            'precio_venta' => 0,
            'stock_minimo' => 0,
            'activo' => true,
        ]);

        $producto->setRelation('codigosPropios', collect());
        $producto->setRelation('presentaciones', collect());
        $producto->setRelation('imagenes', collect());
        $producto->setRelation('variantes', collect());

        return view('catalogo.producto-form', $this->datosFormulario($producto));
    }

    public function store(ProductoRequest $request)
    {
        $producto = new Producto(['tipo' => $request->input('tipo')]);

        $producto = $this->servicio->guardar($producto, $request->datos(), $request->file('imagenes', []));

        $mensaje = $producto->esAgrupador()
            ? 'Producto creado con ' . $producto->variantes()->count() . ' variantes.'
            : 'Producto creado.';

        return redirect()->route('catalogo.productos.edit', $producto)->with('success', $mensaje);
    }

    public function edit(Request $request, Producto $producto)
    {
        $producto->load([
            'padre',
            'unidad',
            'alicuota',
            'codigosPropios',
            'presentaciones.codigo',
            'imagenes',
            'variantes' => fn ($q) => $q->withSum('existencias as stock_total', 'cantidad'),
        ]);

        $existencias = Existencia::query()
            ->with(['deposito', 'ubicacion'])
            ->where('producto_id', $producto->id)
            ->whereIn('deposito_id', $request->user()->depositosPermitidos()->pluck('id'))
            ->where(fn ($q) => $q->where('cantidad', '!=', 0)->orWhere('reservado', '!=', 0))
            ->get()
            ->sortBy(fn ($e) => [$e->deposito?->nombre, $e->ubicacion?->codigo ?? ''])
            ->groupBy('deposito_id');

        return view('catalogo.producto-form', $this->datosFormulario($producto, $existencias));
    }

    public function update(ProductoRequest $request, Producto $producto)
    {
        $this->servicio->guardar($producto, $request->datos(), $request->file('imagenes', []));

        return redirect()->route('catalogo.productos.edit', $producto)->with('success', 'Producto actualizado.');
    }

    public function destroy(Producto $producto)
    {
        $ids = $producto->variantes()->pluck('id')->push($producto->id);

        if (Existencia::whereIn('producto_id', $ids)->where('cantidad', '!=', 0)->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene stock. Podés desactivarlo, o dejar el stock en cero primero.');
        }

        $padreId = $producto->padre_id;
        $this->servicio->eliminar($producto);

        return $padreId
            ? redirect()->route('catalogo.productos.edit', $padreId)->with('success', 'Variante eliminada.')
            : redirect()->route('catalogo.productos.index')->with('success', 'Producto eliminado.');
    }

    /**
     * Búsqueda para autocompletar y lector de códigos (la usan los módulos siguientes).
     * GET /catalogo/productos/buscar?q=779...&stockeables=1
     */
    public function buscar(Request $request)
    {
        $texto = trim((string) $request->query('q'));
        $soloStockeables = $request->boolean('stockeables');
        $depositoId = $request->integer('deposito_id') ?: null;

        if ($texto === '') {
            return response()->json(['exacto' => false, 'resultados' => []]);
        }

        // 1) Código exacto (lector de barras)
        $exacto = false;
        $presentacion = null;
        $encontrado = $this->servicio->buscarPorCodigo($texto);

        if ($encontrado && (! $soloStockeables || $encontrado['producto']->manejaStock())) {
            $resultados = collect([$encontrado['producto']->load('unidad')]);
            $presentacion = $encontrado['presentacion'];
            $exacto = true;
        } else {
            // 2) Coincidencias por nombre o SKU
            $resultados = Producto::with('unidad')
                ->activos()
                ->when($soloStockeables, fn ($q) => $q->stockeables())
                ->when(! $soloStockeables, fn ($q) => $q->where('tipo', '!=', Producto::TIPO_CON_VARIANTES))
                ->buscar($texto)
                ->orderBy('nombre')
                ->limit(15)
                ->get();
        }

        $resumen = $depositoId && $resultados->isNotEmpty()
            ? app(StockService::class)->resumen($resultados->pluck('id')->all(), $depositoId)
            : [];

        $conocidos = \App\Http\Controllers\Inventario\MovimientoController::lotesConocidos(
            $resultados->where('usa_lotes', true)->pluck('id')->all()
        );

        $formatear = fn (Producto $p) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'nombre' => $p->nombre,
            'tipo' => $p->tipo,
            'unidad' => $p->unidad?->abreviatura,
            'permite_decimales' => (bool) $p->unidad?->permite_decimales,
            'costo' => $p->costo_valuacion,
            'precio_venta' => (float) $p->precio_venta,
            'usa_lotes' => $p->usa_lotes,
            'usa_series' => $p->usa_series,
            'stock' => $depositoId ? (float) ($resumen[$p->id]['total'] ?? 0) : null,
            'stock_reservado' => $depositoId ? (float) ($resumen[$p->id]['reservado'] ?? 0) : null,
            'stock_disponible' => $depositoId ? (float) ($resumen[$p->id]['disponible'] ?? 0) : null,
            'stock_ubicaciones' => $depositoId ? (object) ($resumen[$p->id]['ubicaciones'] ?? []) : null,
            'stock_lotes' => $depositoId ? ($resumen[$p->id]['lotes'] ?? []) : null,
            'lotes_conocidos' => $p->usa_lotes ? ($conocidos[$p->id] ?? []) : [],
            'presentacion' => $presentacion && $presentacion->producto_id === $p->id ? [
                'id' => $presentacion->id,
                'nombre' => $presentacion->nombre,
                'cantidad' => (float) $presentacion->cantidad,
            ] : null,
        ];

        return response()->json([
            'exacto' => $exacto,
            'resultados' => $resultados->map($formatear)->values(),
        ]);
    }

    /**
     * Stock de varios productos en un depósito (total y por ubicación).
     * GET /catalogo/productos/stock?deposito_id=1&ids[]=5&ids[]=8
     */
    public function stock(Request $request, StockService $stock)
    {
        $datos = $request->validate([
            'deposito_id' => ['required', 'integer'],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $depositoId = (int) $datos['deposito_id'];
        abort_unless($request->user()->puedeOperarDeposito($depositoId), 403);

        $ids = array_values(array_unique(array_map('intval', $datos['ids'])));
        $resumen = $stock->resumen($ids, $depositoId);

        $respuesta = [];
        foreach ($ids as $id) {
            $respuesta[$id] = [
                'total' => (float) ($resumen[$id]['total'] ?? 0),
                'reservado' => (float) ($resumen[$id]['reservado'] ?? 0),
                'disponible' => (float) ($resumen[$id]['disponible'] ?? 0),
                'ubicaciones' => (object) ($resumen[$id]['ubicaciones'] ?? []),
                'lotes' => $resumen[$id]['lotes'] ?? [],
            ];
        }

        return response()->json((object) $respuesta);
    }

    protected function datosFormulario(Producto $producto, $existencias = null): array
    {
        return [
            'producto' => $producto,
            'existencias' => $existencias ?? collect(),
            'empresa' => EmpresaActual::modelo(),
            'categorias' => Categoria::arbol(true),
            'marcas' => Marca::activas()->orderBy('nombre')->get(),
            'unidades' => UnidadMedida::activas()->orderBy('tipo')->orderBy('nombre')->get(),
            'alicuotas' => AlicuotaIva::activas()->get(),
            'tiposCodigo' => ProductoCodigo::TIPOS,
        ];
    }
}
