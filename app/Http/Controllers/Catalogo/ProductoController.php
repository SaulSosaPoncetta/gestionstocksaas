<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductoRequest;
use App\Models\AlicuotaIva;
use App\Models\Categoria;
use App\Models\Marca;
use App\Models\Producto;
use App\Models\ProductoCodigo;
use App\Models\UnidadMedida;
use App\Services\ProductoService;
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

        $consulta = Producto::query()
            ->with(['categoria', 'marca', 'alicuota', 'unidad', 'imagenPrincipal'])
            ->withCount('variantes');

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

        return view('catalogo.productos', [
            'productos' => $productos,
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

    public function edit(Producto $producto)
    {
        $producto->load([
            'padre',
            'codigosPropios',
            'presentaciones.codigo',
            'imagenes',
            'variantes',
        ]);

        return view('catalogo.producto-form', $this->datosFormulario($producto));
    }

    public function update(ProductoRequest $request, Producto $producto)
    {
        $this->servicio->guardar($producto, $request->datos(), $request->file('imagenes', []));

        return redirect()->route('catalogo.productos.edit', $producto)->with('success', 'Producto actualizado.');
    }

    public function destroy(Producto $producto)
    {
        // En el paso 6 se agrega el control de stock y movimientos
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

        if ($texto === '') {
            return response()->json([]);
        }

        $formatear = fn (Producto $p, $presentacion = null) => [
            'id' => $p->id,
            'sku' => $p->sku,
            'nombre' => $p->nombre,
            'tipo' => $p->tipo,
            'unidad' => $p->unidad?->abreviatura,
            'permite_decimales' => (bool) $p->unidad?->permite_decimales,
            'costo' => (float) $p->costo,
            'precio_venta' => (float) $p->precio_venta,
            'usa_lotes' => $p->usa_lotes,
            'usa_series' => $p->usa_series,
            'presentacion' => $presentacion ? [
                'id' => $presentacion->id,
                'nombre' => $presentacion->nombre,
                'cantidad' => (float) $presentacion->cantidad,
            ] : null,
        ];

        // 1) Código exacto (lector de barras)
        if ($encontrado = $this->servicio->buscarPorCodigo($texto)) {
            $producto = $encontrado['producto']->load('unidad');

            if (! $soloStockeables || $producto->manejaStock()) {
                return response()->json([$formatear($producto, $encontrado['presentacion'])]);
            }
        }

        // 2) Coincidencias por nombre o SKU
        $resultados = Producto::with('unidad')
            ->activos()
            ->when($soloStockeables, fn ($q) => $q->stockeables())
            ->when(! $soloStockeables, fn ($q) => $q->where('tipo', '!=', Producto::TIPO_CON_VARIANTES))
            ->buscar($texto)
            ->orderBy('nombre')
            ->limit(15)
            ->get();

        return response()->json($resultados->map(fn ($p) => $formatear($p))->values());
    }

    protected function datosFormulario(Producto $producto): array
    {
        return [
            'producto' => $producto,
            'empresa' => EmpresaActual::modelo(),
            'categorias' => Categoria::arbol(true),
            'marcas' => Marca::activas()->orderBy('nombre')->get(),
            'unidades' => UnidadMedida::activas()->orderBy('tipo')->orderBy('nombre')->get(),
            'alicuotas' => AlicuotaIva::activas()->get(),
            'tiposCodigo' => ProductoCodigo::TIPOS,
        ];
    }
}
