<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use App\Services\CompraService;
use Illuminate\Http\Request;

class ReposicionController extends Controller
{
    public function __construct(protected CompraService $compras)
    {
    }

    public function index(Request $request)
    {
        $depositos = $request->user()->depositosPermitidos();
        $depositoId = $request->integer('deposito_id') ?: ($depositos->firstWhere('es_principal', true)?->id ?? $depositos->first()?->id);

        if ($depositoId && ! $depositos->contains('id', $depositoId)) {
            $depositoId = $depositos->first()?->id;
        }

        $proveedorId = $request->integer('proveedor_id') ?: null;

        $sugerencias = $depositoId ? $this->compras->sugerencias([$depositoId], $proveedorId) : collect();

        return view('compras.reposicion', [
            'sugerencias' => $sugerencias->sortBy(fn ($s) => [$s->vinculo?->proveedor?->razon_social ?? 'ZZZ', $s->producto->nombre])->values(),
            'depositos' => $depositos,
            'proveedores' => Proveedor::activos()->orderBy('razon_social')->get(),
            'filtros' => ['deposito_id' => $depositoId, 'proveedor_id' => $proveedorId],
        ]);
    }

    public function generar(Request $request)
    {
        $datos = $request->validate([
            'deposito_id' => ['required', 'integer'],
            'seleccion' => ['required', 'array'],
            'seleccion.*.producto_id' => ['required', 'integer'],
            'seleccion.*.proveedor_id' => ['nullable', 'integer'],
            'seleccion.*.cantidad' => ['nullable', 'numeric', 'min:0'],
            'seleccion.*.incluir' => ['nullable'],
        ]);

        abort_unless($request->user()->puedeOperarDeposito((int) $datos['deposito_id']), 403);

        $elegidas = array_values(array_filter($datos['seleccion'], fn ($fila) => ! empty($fila['incluir'])));
        $ordenes = $this->compras->generarOrdenes($elegidas, (int) $datos['deposito_id'], $request->user());

        $numeros = collect($ordenes)->map(fn ($o) => $o->numero_texto)->implode(', ');

        return redirect()->route('compras.ordenes.index', ['estado' => 'borrador'])
            ->with('success', count($ordenes) === 1
                ? "Se generó la orden de compra N° {$numeros} en borrador. Revisala y envíala al proveedor."
                : 'Se generaron ' . count($ordenes) . " órdenes de compra en borrador (N° {$numeros}). Revisalas y envíalas.");
    }
}
