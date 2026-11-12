<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Motivo;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MotivoController extends Controller
{
    public function index()
    {
        $motivos = Motivo::query()
            ->withCount('movimientos')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->groupBy('tipo');

        return view('configuracion.motivos', [
            'motivos' => $motivos,
            'tipos' => Motivo::TIPOS,
        ]);
    }

    public function store(Request $request)
    {
        Motivo::create($this->validar($request));

        return redirect()->route('configuracion.motivos.index')->with('success', 'Motivo creado.');
    }

    public function update(Request $request, Motivo $motivo)
    {
        $datos = $this->validar($request, $motivo);

        // El tipo no se cambia si ya se usó
        if ($motivo->movimientos()->exists()) {
            $datos['tipo'] = $motivo->tipo;
        }

        $motivo->update($datos);

        return redirect()->route('configuracion.motivos.index')->with('success', 'Motivo actualizado.');
    }

    public function destroy(Motivo $motivo)
    {
        if ($motivo->movimientos()->exists()) {
            return back()->with('error', "No se puede eliminar \"{$motivo->nombre}\": ya se usó en movimientos. Podés desactivarlo.");
        }

        $motivo->delete();

        return redirect()->route('configuracion.motivos.index')->with('success', 'Motivo eliminado.');
    }

    protected function validar(Request $request, ?Motivo $motivo = null): array
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Motivo::TIPOS))],
            'nombre' => [
                'required', 'string', 'max:80',
                Rule::unique('motivos', 'nombre')
                    ->where('empresa_id', EmpresaActual::id())
                    ->where('tipo', $request->input('tipo'))
                    ->ignore($motivo?->id),
            ],
            'orden' => ['nullable', 'integer', 'between:0,999'],
        ], [
            'nombre.unique' => 'Ya existe un motivo con ese nombre para ese tipo de movimiento.',
        ]);

        $datos['orden'] = $datos['orden'] ?? 0;
        $datos['pide_costo'] = $datos['tipo'] === 'ingreso' && $request->boolean('pide_costo');
        $datos['requiere_observacion'] = $request->boolean('requiere_observacion');
        $datos['activo'] = $request->boolean('activo');

        return $datos;
    }
}
