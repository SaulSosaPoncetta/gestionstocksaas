<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\Categoria;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CategoriaController extends Controller
{
    public function index()
    {
        return view('catalogo.categorias', [
            'categorias' => Categoria::arbol(),
        ]);
    }

    public function store(Request $request)
    {
        Categoria::create($this->validar($request));

        return redirect()->route('catalogo.categorias.index')->with('success', 'Categoría creada.');
    }

    public function update(Request $request, Categoria $categoria)
    {
        $datos = $this->validar($request, $categoria);

        if ($datos['padre_id'] !== null) {
            $prohibidos = array_merge([$categoria->id], $categoria->descendientesIds());

            if (in_array((int) $datos['padre_id'], $prohibidos, true)) {
                throw ValidationException::withMessages([
                    'padre_id' => 'Una categoría no puede quedar dentro de sí misma ni de una de sus subcategorías.',
                ]);
            }
        }

        $categoria->update($datos);

        return redirect()->route('catalogo.categorias.index')->with('success', 'Categoría actualizada.');
    }

    public function destroy(Categoria $categoria)
    {
        if ($categoria->hijos()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene subcategorías. Movelas o eliminalas primero.');
        }

        if (Producto::where('categoria_id', $categoria->id)->exists()) {
            return back()->with('error', 'No se puede eliminar: hay productos en esta categoría.');
        }

        $categoria->delete();

        return redirect()->route('catalogo.categorias.index')->with('success', 'Categoría eliminada.');
    }

    protected function validar(Request $request, ?Categoria $categoria = null): array
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100'],
            'codigo' => ['nullable', 'string', 'max:30'],
            'descripcion' => ['nullable', 'string', 'max:500'],
            'padre_id' => [
                'nullable',
                'integer',
                Rule::exists('categorias', 'id')
                    ->where('empresa_id', EmpresaActual::id())
                    ->whereNull('deleted_at'),
            ],
            'orden' => ['nullable', 'integer', 'between:0,9999'],
        ]);

        $datos['padre_id'] = $datos['padre_id'] ?? null;
        $datos['orden'] = $datos['orden'] ?? 0;
        $datos['activa'] = $request->boolean('activa');

        $repetida = Categoria::query()
            ->where('nombre', $datos['nombre'])
            ->where('padre_id', $datos['padre_id'])
            ->when($categoria, fn ($q) => $q->whereKeyNot($categoria->id))
            ->exists();

        if ($repetida) {
            throw ValidationException::withMessages([
                'nombre' => 'Ya existe una categoría con ese nombre en el mismo nivel.',
            ]);
        }

        return $datos;
    }
}
