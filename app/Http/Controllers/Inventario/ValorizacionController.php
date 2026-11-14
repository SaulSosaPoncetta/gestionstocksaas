<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Deposito;
use App\Models\Marca;
use App\Models\Motivo;
use App\Services\ValorizacionService;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ValorizacionController extends Controller
{
    public function __construct(protected ValorizacionService $valorizacion)
    {
    }

    /** Stock de hoy valorizado a costo y a precio de venta. */
    public function index(Request $request)
    {
        [$depositos, $ids] = $this->depositos($request);
        $filtros = $this->filtros($request);
        $datos = $this->valorizacion->actual($ids, $filtros);

        if ($request->query('exportar') === 'csv') {
            return Csv::descargar('valorizacion-' . today()->format('Y-m-d'), $this->encabezados(true), $this->filasCsv($datos['filas'], true));
        }

        return view('inventario.valorizacion', $this->vista($request, $datos, $depositos, $filtros) + [
            'pestania' => 'actual',
        ]);
    }

    /** Stock que había al cierre de un día pasado (reconstruido desde el kardex). */
    public function historica(Request $request)
    {
        $request->validate(['fecha' => ['nullable', 'date', 'before_or_equal:today']]);

        [$depositos, $ids] = $this->depositos($request);
        $filtros = $this->filtros($request);
        $fecha = $request->filled('fecha') ? Carbon::parse($request->query('fecha')) : today()->subMonthNoOverflow()->endOfMonth();
        $datos = $this->valorizacion->aFecha($fecha, $ids, $filtros);

        if ($request->query('exportar') === 'csv') {
            return Csv::descargar('valorizacion-al-' . $fecha->format('Y-m-d'), $this->encabezados(false), $this->filasCsv($datos['filas'], false));
        }

        return view('inventario.valorizacion', $this->vista($request, $datos, $depositos, $filtros) + [
            'pestania' => 'historica',
            'fecha' => $fecha,
        ]);
    }

    /** Costo de lo que salió en un período, por motivo y por producto. */
    public function salidas(Request $request)
    {
        $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ]);

        [$depositos, $ids] = $this->depositos($request);
        $filtros = $this->filtros($request);

        $desde = $request->filled('desde') ? Carbon::parse($request->query('desde')) : today()->startOfMonth();
        $hasta = $request->filled('hasta') ? Carbon::parse($request->query('hasta')) : today();

        // Por defecto el detalle muestra las ventas
        $motivoVenta = Motivo::where('tipo', 'egreso')->where('nombre', 'Venta')->value('id');
        $motivo = $request->has('motivo_id') ? ($request->query('motivo_id') === '' ? null : (int) $request->query('motivo_id')) : $motivoVenta;
        $filtros['motivo_id'] = $motivo;

        $datos = $this->valorizacion->costoSalidas($desde, $hasta, $ids, $filtros);

        if ($request->query('exportar') === 'csv') {
            return Csv::descargar(
                'costo-salidas-' . $desde->format('Y-m-d') . '-a-' . $hasta->format('Y-m-d'),
                ['SKU', 'Producto', 'Categoría', 'Cantidad', 'Unidad', 'Costo unitario', 'Costo total', 'Venta estimada (lista)', 'Margen estimado', 'Margen %'],
                $datos['productos']->map(fn ($p) => [
                    $p['producto']->sku, $p['producto']->nombre, $p['producto']->categoria?->nombre,
                    (float) $p['cantidad'], $p['producto']->unidad?->abreviatura, (float) $p['costo_unitario'],
                    round($p['costo'], 2), round($p['venta_estimada'], 2), round($p['margen'], 2),
                    $p['margen_pct'] === null ? null : round($p['margen_pct'], 1),
                ])
            );
        }

        return view('inventario.valorizacion-salidas', [
            'pestania' => 'salidas',
            'datos' => $datos,
            'depositos' => $depositos,
            'filtros' => $filtros + ['deposito_id' => $request->integer('deposito_id') ?: null],
            'desde' => $desde,
            'hasta' => $hasta,
            'motivoElegido' => $motivo,
            'categorias' => Categoria::arbol(),
            'marcas' => Marca::orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /* ---------------------------------------------------------- */

    /** Depósitos que puede ver el usuario (+ "En tránsito" si ve todos) y los elegidos en el filtro. */
    protected function depositos(Request $request): array
    {
        $usuario = $request->user();
        $depositos = $usuario->depositosPermitidos();

        if ($usuario->operaTodosLosDepositos() && ($transito = Deposito::where('es_transito', true)->first())) {
            $depositos = $depositos->push($transito);
        }

        $elegido = $request->integer('deposito_id');
        $ids = $elegido && $depositos->contains('id', $elegido) ? [$elegido] : $depositos->pluck('id')->all();

        return [$depositos, $ids];
    }

    protected function filtros(Request $request): array
    {
        return [
            'categoria_id' => $request->integer('categoria_id') ?: null,
            'marca_id' => $request->integer('marca_id') ?: null,
            'q' => trim((string) $request->query('q')),
            'con_stock' => ! $request->boolean('incluir_ceros'),
        ];
    }

    protected function vista(Request $request, array $datos, Collection $depositos, array $filtros): array
    {
        $porPagina = 50;
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $filas = new LengthAwarePaginator(
            $datos['filas']->forPage($pagina, $porPagina)->values(),
            $datos['filas']->count(),
            $porPagina,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return [
            'datos' => $datos,
            'filas' => $filas,
            'depositos' => $depositos,
            'nombresDeposito' => $depositos->pluck('nombre', 'id'),
            'filtros' => $filtros + ['deposito_id' => $request->integer('deposito_id') ?: null, 'incluir_ceros' => $request->boolean('incluir_ceros')],
            'categorias' => Categoria::arbol(),
            'marcas' => Marca::orderBy('nombre')->get(['id', 'nombre']),
        ];
    }

    protected function encabezados(bool $conPrecio): array
    {
        return array_merge(
            ['SKU', 'Producto', 'Categoría', 'Marca', 'Cantidad', 'Unidad', 'Costo unitario', 'Valor a costo'],
            $conPrecio ? ['Precio de venta (neto)', 'Valor a venta', 'Margen potencial', 'Margen %'] : []
        );
    }

    protected function filasCsv(Collection $filas, bool $conPrecio): iterable
    {
        foreach ($filas as $f) {
            $p = $f['producto'];
            $fila = [$p->sku, $p->nombre, $p->categoria?->nombre, $p->marca?->nombre, (float) $f['cantidad'], $p->unidad?->abreviatura, (float) $f['costo'], (float) $f['valor_costo']];

            if ($conPrecio) {
                $fila = array_merge($fila, [(float) $f['precio'], (float) $f['valor_venta'], (float) $f['margen'], $f['margen_pct'] === null ? null : round($f['margen_pct'], 1)]);
            }

            yield $fila;
        }
    }
}
