<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\Marca;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MarcaController extends Controller
{
    public function index(Request $request)
    {
        $buscar = trim((string) $request->query('q'));

        $marcas = Marca::query()
            ->when($buscar !== '', fn ($q) => $q->where('nombre', 'like', "%{$buscar}%"))
            ->orderBy('nombre')
            ->paginate(30)
            ->withQueryString();

        return view('catalogo.marcas', compact('marcas', 'buscar'));
    }

    public function store(Request $request)
    {
        Marca::create($this->validar($request));

        return redirect()->route('catalogo.marcas.index')->with('success', 'Marca creada.');
    }

    public function update(Request $request, Marca $marca)
    {
        $marca->update($this->validar($request, $marca));

        return redirect()->route('catalogo.marcas.index')->with('success', 'Marca actualizada.');
    }

    public function destroy(Marca $marca)
    {
        if (Producto::where('marca_id', $marca->id)->exists()) {
            return back()->with('error', 'No se puede eliminar: hay productos de esta marca. Podés desactivarla.');
        }

        $marca->delete();

        return redirect()->route('catalogo.marcas.index')->with('success', 'Marca eliminada.');
    }

    protected function validar(Request $request, ?Marca $marca = null): array
    {
        $datos = $request->validate([
            'nombre' => [
                'required',
                'string',
                'max:100',
                Rule::unique('marcas', 'nombre')
                    ->where('empresa_id', EmpresaActual::id())
                    ->whereNull('deleted_at')
                    ->ignore($marca?->id),
            ],
        ]);

        $datos['activa'] = $request->boolean('activa');

        return $datos;
    }
}
