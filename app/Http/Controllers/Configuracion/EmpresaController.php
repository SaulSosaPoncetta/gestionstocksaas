<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EmpresaController extends Controller
{
    public const CONDICIONES_IVA = [
        'responsable_inscripto' => 'Responsable Inscripto',
        'monotributo' => 'Monotributo',
        'exento' => 'Exento',
        'consumidor_final' => 'Consumidor Final',
    ];

    public const METODOS_COSTEO = [
        Empresa::COSTEO_PROMEDIO => 'Costo promedio ponderado',
        Empresa::COSTEO_PEPS => 'PEPS (primero entrado, primero salido)',
    ];

    public function edit()
    {
        return view('configuracion.empresa', [
            'empresa' => EmpresaActual::modelo(),
            'condiciones' => self::CONDICIONES_IVA,
            'metodos' => self::METODOS_COSTEO,
        ]);
    }

    public function update(Request $request)
    {
        $empresa = EmpresaActual::modelo();

        $datos = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_fantasia' => ['nullable', 'string', 'max:255'],
            'condicion_iva' => ['required', Rule::in(array_keys(self::CONDICIONES_IVA))],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'localidad' => ['nullable', 'string', 'max:255'],
            'provincia' => ['nullable', 'string', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
            // Nota: en el paso 13 se bloquea el cambio de método de costeo si ya hay movimientos
            'metodo_costeo' => ['required', Rule::in(array_keys(self::METODOS_COSTEO))],
            'decimales_cantidad' => ['required', 'integer', 'between:0,4'],
            'dias_aviso_vencimiento' => ['required', 'integer', 'between:1,365'],
        ]);

        $datos['permite_stock_negativo'] = $request->boolean('permite_stock_negativo');
        $datos['usa_lotes'] = $request->boolean('usa_lotes');
        $datos['usa_series'] = $request->boolean('usa_series');

        unset($datos['logo']);

        if ($request->hasFile('logo')) {
            if ($empresa->logo) {
                Storage::disk('public')->delete($empresa->logo);
            }
            $datos['logo'] = $request->file('logo')->store("empresas/{$empresa->id}", 'public');
        }

        $empresa->update($datos);

        return redirect()->route('configuracion.empresa.edit')->with('success', 'Datos de la empresa actualizados.');
    }
}
