<?php

namespace App\Http\Controllers\Catalogo;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Models\UnidadMedida;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UnidadMedidaController extends Controller
{
    public function index()
    {
        $unidades = UnidadMedida::with('base')
            ->withCount('derivadas')
            ->orderBy('tipo')
            ->orderByRaw('unidad_base_id is not null')
            ->orderBy('nombre')
            ->get();

        return view('catalogo.unidades', [
            'unidades' => $unidades,
            'bases' => $unidades->whereNull('unidad_base_id')->values(),
            'tipos' => UnidadMedida::TIPOS,
        ]);
    }

    public function store(Request $request)
    {
        UnidadMedida::create($this->validar($request));

        return redirect()->route('catalogo.unidades.index')->with('success', 'Unidad de medida creada.');
    }

    public function update(Request $request, UnidadMedida $unidad)
    {
        $unidad->update($this->validar($request, $unidad));

        return redirect()->route('catalogo.unidades.index')->with('success', 'Unidad de medida actualizada.');
    }

    public function destroy(UnidadMedida $unidad)
    {
        if ($unidad->derivadas()->exists()) {
            return back()->with('error', "No se puede eliminar: otras unidades dependen de {$unidad->nombre}.");
        }

        if (Producto::withTrashed()->where('unidad_medida_id', $unidad->id)->exists()) {
            return back()->with('error', "No se puede eliminar: hay productos que usan {$unidad->nombre}. Podés desactivarla.");
        }

        $unidad->delete();

        return redirect()->route('catalogo.unidades.index')->with('success', 'Unidad de medida eliminada.');
    }

    protected function validar(Request $request, ?UnidadMedida $unidad = null): array
    {
        $empresaId = EmpresaActual::id();

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:50'],
            'abreviatura' => [
                'required',
                'string',
                'max:10',
                Rule::unique('unidades_medida', 'abreviatura')->where('empresa_id', $empresaId)->ignore($unidad?->id),
            ],
            'tipo' => ['required', Rule::in(array_keys(UnidadMedida::TIPOS))],
            'unidad_base_id' => [
                'nullable',
                'integer',
                Rule::exists('unidades_medida', 'id')
                    ->where('empresa_id', $empresaId)
                    ->whereNull('unidad_base_id'),
            ],
            'factor' => ['nullable', 'required_with:unidad_base_id', 'numeric', 'gt:0', 'max:999999999'],
        ]);

        $datos['unidad_base_id'] = $datos['unidad_base_id'] ?? null;
        $datos['permite_decimales'] = $request->boolean('permite_decimales');
        $datos['activa'] = $request->boolean('activa');

        if ($datos['unidad_base_id'] === null) {
            $datos['factor'] = 1;
        } else {
            $base = UnidadMedida::find($datos['unidad_base_id']);

            if ($unidad && $base->id === $unidad->id) {
                throw ValidationException::withMessages(['unidad_base_id' => 'Una unidad no puede ser su propia base.']);
            }

            if ($base->tipo !== $datos['tipo']) {
                throw ValidationException::withMessages(['unidad_base_id' => 'La unidad base tiene que ser del mismo tipo.']);
            }

            if ($unidad && $unidad->derivadas()->exists()) {
                throw ValidationException::withMessages([
                    'unidad_base_id' => "{$unidad->nombre} es base de otras unidades, así que no puede depender de otra.",
                ]);
            }
        }

        return $datos;
    }
}
