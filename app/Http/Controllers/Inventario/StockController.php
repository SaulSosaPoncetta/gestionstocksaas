<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Existencia;
use App\Models\MovimientoItem;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StockController extends Controller
{
    public const ESTADOS = [
        'todos' => 'Todos',
        'con_stock' => 'Con stock',
        'sin_stock' => 'Sin stock',
        'bajo_minimo' => 'Bajo el mínimo',
        'reponer' => 'En punto de reposición',
        'negativo' => 'Negativo',
    ];

    /** Consulta de existencias por depósito. */
    public function index(Request $request)
    {
        $depositos = $request->user()->depositosPermitidos();
        $depositoId = $request->integer('deposito_id') ?: null;

        if ($depositoId && ! $depositos->contains('id', $depositoId)) {
            $depositoId = null;
        }

        $ids = $depositoId ? [$depositoId] : $depositos->pluck('id')->all();

        $filtros = [
            'q' => trim((string) $request->query('q')),
            'deposito_id' => $depositoId,
            'categoria_id' => $request->integer('categoria_id') ?: null,
            'estado' => array_key_exists((string) $request->query('estado'), self::ESTADOS) ? $request->query('estado') : 'todos',
        ];

        $consulta = Producto::query()
            ->stockeables()
            ->activos()
            ->with(['unidad', 'categoria'])
            ->withSum(['existencias as stock' => fn ($q) => $q->whereIn('deposito_id', $ids)], 'cantidad')
            ->withSum(['existencias as reservado' => fn ($q) => $q->whereIn('deposito_id', $ids)], 'reservado')
            ->buscar($filtros['q']);

        if ($filtros['categoria_id']) {
            $categoria = Categoria::find($filtros['categoria_id']);
            $consulta->whereIn('categoria_id', $categoria ? array_merge([$categoria->id], $categoria->descendientesIds()) : [0]);
        }

        match ($filtros['estado']) {
            'con_stock' => $consulta->havingRaw('COALESCE(stock, 0) > 0'),
            'sin_stock' => $consulta->havingRaw('COALESCE(stock, 0) = 0'),
            'negativo' => $consulta->havingRaw('COALESCE(stock, 0) < 0'),
            'bajo_minimo' => $consulta->where('stock_minimo', '>', 0)->havingRaw('COALESCE(stock, 0) < productos.stock_minimo'),
            'reponer' => $consulta->whereNotNull('punto_reposicion')->havingRaw('COALESCE(stock, 0) <= productos.punto_reposicion'),
            default => null,
        };

        $productos = $consulta->orderBy('nombre')->paginate(50)->withQueryString();

        // Valor total del stock en los depósitos seleccionados (a costo promedio)
        $valorTotal = (float) Existencia::query()
            ->join('productos', 'productos.id', '=', 'existencias.producto_id')
            ->whereIn('existencias.deposito_id', $ids)
            ->selectRaw('SUM(existencias.cantidad * CASE WHEN productos.costo_promedio > 0 THEN productos.costo_promedio ELSE productos.costo END) AS total')
            ->value('total');

        return view('inventario.stock', [
            'productos' => $productos,
            'filtros' => $filtros,
            'depositos' => $depositos,
            'categorias' => Categoria::arbol(),
            'estados' => self::ESTADOS,
            'valorTotal' => $valorTotal,
        ]);
    }

    /** Kardex de un producto: cada entrada y salida con su saldo. */
    public function kardex(Request $request, Producto $producto)
    {
        if (! $producto->manejaStock()) {
            return redirect()->route('catalogo.productos.edit', $producto)
                ->with('warning', 'Este producto no controla stock, así que no tiene kardex.');
        }

        $producto->load(['unidad', 'padre']);

        $depositos = $request->user()->depositosPermitidos();
        $depositoId = $request->integer('deposito_id') ?: null;

        if ($depositoId && ! $depositos->contains('id', $depositoId)) {
            $depositoId = null;
        }

        $ids = $depositoId ? [$depositoId] : $depositos->pluck('id')->all();

        $desde = $this->fecha($request->query('desde'));
        $hasta = $this->fecha($request->query('hasta'));

        $items = MovimientoItem::query()
            ->with(['movimiento.usuario', 'movimiento.motivo', 'deposito', 'ubicacion', 'presentacion', 'lote'])
            ->where('producto_id', $producto->id)
            ->whereIn('deposito_id', $ids)
            ->when($desde, fn ($q) => $q->where('fecha', '>=', $desde->copy()->startOfDay()))
            ->when($hasta, fn ($q) => $q->where('fecha', '<=', $hasta->copy()->endOfDay()))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Stock actual por depósito y ubicación
        $existencias = Existencia::query()
            ->with(['deposito', 'ubicacion', 'lote'])
            ->where('producto_id', $producto->id)
            ->whereIn('deposito_id', $depositos->pluck('id'))
            ->where('cantidad', '!=', 0)
            ->get()
            ->sortBy(fn ($e) => [$e->deposito?->nombre, $e->lote?->fecha_vencimiento?->toDateString() ?? '9999', $e->ubicacion?->codigo ?? ''])
            ->groupBy('deposito_id');

        return view('inventario.kardex', [
            'producto' => $producto,
            'items' => $items,
            'existencias' => $existencias,
            'depositos' => $depositos,
            'filtros' => [
                'deposito_id' => $depositoId,
                'desde' => $desde?->toDateString(),
                'hasta' => $hasta?->toDateString(),
            ],
        ]);
    }

    protected function fecha(?string $valor): ?Carbon
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
