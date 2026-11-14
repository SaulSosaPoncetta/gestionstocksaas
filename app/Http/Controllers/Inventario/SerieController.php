<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Serie;
use Illuminate\Http\Request;

class SerieController extends Controller
{
    /** Buscador de números de serie. */
    public function index(Request $request)
    {
        $usuario = $request->user();
        $depositos = $usuario->depositosPermitidos();
        $texto = trim((string) $request->query('q'));
        $estado = in_array($request->query('estado'), [Serie::EN_STOCK, Serie::EGRESADO], true) ? $request->query('estado') : null;
        $depositoId = $request->integer('deposito_id') ?: null;

        // Búsqueda exacta: si hay una sola coincidencia se va directo a su historial
        if ($texto !== '' && ! $estado && ! $depositoId) {
            $exactas = Serie::where('numero', $texto)->limit(2)->get();

            if ($exactas->count() === 1) {
                return redirect()->route('inventario.series.show', $exactas->first());
            }
        }

        $series = Serie::query()
            ->with(['producto', 'deposito', 'ubicacion', 'lote'])
            ->when($texto !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('numero', 'like', "%{$texto}%")
                ->orWhereHas('producto', fn ($p) => $p->where('nombre', 'like', "%{$texto}%")->orWhere('sku', $texto))))
            ->when($estado, fn ($q, $e) => $q->where('estado', $e))
            ->when($depositoId, fn ($q, $id) => $q->where('deposito_id', $id))
            ->when(! $usuario->operaTodosLosDepositos() && ! $depositoId, fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('deposito_id', $depositos->pluck('id'))
                ->orWhereNull('deposito_id')))
            ->orderBy('numero')
            ->paginate(50)
            ->withQueryString();

        return view('inventario.series', [
            'series' => $series,
            'depositos' => $depositos,
            'filtros' => ['q' => $texto, 'estado' => $estado, 'deposito_id' => $depositoId],
        ]);
    }

    /** Historial completo de una serie. */
    public function show(Serie $serie)
    {
        $serie->load([
            'producto',
            'deposito',
            'ubicacion',
            'lote',
            'items.movimiento.usuario',
            'items.movimiento.motivo',
            'items.deposito',
            'items.ubicacion',
        ]);

        return view('inventario.serie', ['serie' => $serie]);
    }
}
