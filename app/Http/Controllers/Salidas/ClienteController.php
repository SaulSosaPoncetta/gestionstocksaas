<?php

namespace App\Http\Controllers\Salidas;

use App\Http\Controllers\Admin\EmpresaAdminController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        $buscar = trim((string) $request->query('q'));

        $clientes = Cliente::query()
            ->withCount(['reservas as reservas_activas_count' => fn ($q) => $q->where('estado', 'activa')])
            ->buscar($buscar)
            ->when($request->query('estado', 'activos') === 'activos', fn ($q) => $q->where('activo', true))
            ->orderBy('razon_social')
            ->paginate(30)
            ->withQueryString();

        return view('salidas.clientes', [
            'clientes' => $clientes,
            'buscar' => $buscar,
            'maestroExterno' => Cliente::maestroExterno(),
        ]);
    }

    public function create()
    {
        $this->verificarMaestro();

        return view('salidas.cliente-form', [
            'cliente' => new Cliente(['activo' => true, 'documento_tipo' => 'CUIT', 'condicion_iva' => 'consumidor_final']),
            'condiciones' => EmpresaController::CONDICIONES_IVA,
            'documentos' => Cliente::DOCUMENTOS,
        ]);
    }

    public function store(Request $request)
    {
        $this->verificarMaestro();
        $cliente = Cliente::create($this->validar($request));

        // Vuelve a la pantalla de origen (solo direcciones de este mismo sistema)
        $volver = (string) $request->input('volver');
        if ($volver !== '' && str_starts_with($volver, rtrim(url('/'), '/') . '/')) {
            return redirect()->to($volver)->with('success', "Cliente {$cliente->nombre} creado.");
        }

        return redirect()->route('salidas.clientes.show', $cliente)->with('success', 'Cliente creado.');
    }

    public function show(Cliente $cliente)
    {
        return view('salidas.cliente', [
            'cliente' => $cliente,
            'reservas' => $cliente->reservas()->with('deposito')->orderByDesc('id')->limit(10)->get(),
            'remitos' => $cliente->remitos()->with('deposito')->withCount('items')->orderByDesc('id')->limit(10)->get(),
            'maestroExterno' => Cliente::maestroExterno(),
        ]);
    }

    public function edit(Cliente $cliente)
    {
        $this->verificarMaestro();

        return view('salidas.cliente-form', [
            'cliente' => $cliente,
            'condiciones' => EmpresaController::CONDICIONES_IVA,
            'documentos' => Cliente::DOCUMENTOS,
        ]);
    }

    public function update(Request $request, Cliente $cliente)
    {
        $this->verificarMaestro();
        $cliente->update($this->validar($request, $cliente));

        return redirect()->route('salidas.clientes.show', $cliente)->with('success', 'Cliente actualizado.');
    }

    protected function validar(Request $request, ?Cliente $cliente = null): array
    {
        $tipo = $request->input('documento_tipo');

        if (in_array($tipo, ['CUIT', 'CUIL'], true) && filled($request->input('documento'))) {
            $request->merge(['documento' => \App\Models\Empresa::normalizarCuit((string) $request->input('documento'))]);
        }

        $datos = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_fantasia' => ['nullable', 'string', 'max:255'],
            'documento_tipo' => ['required', Rule::in(array_keys(Cliente::DOCUMENTOS))],
            'documento' => [
                'nullable', 'string', 'max:20',
                function ($atributo, $valor, $fallar) use ($tipo) {
                    if (! $valor) {
                        return;
                    }
                    if (in_array($tipo, ['CUIT', 'CUIL'], true) && ! EmpresaAdminController::cuitValido($valor)) {
                        $fallar("El {$tipo} no es válido.");
                    }
                    if ($tipo === 'DNI' && ! preg_match('/^\d{7,8}$/', $valor)) {
                        $fallar('El DNI tiene que tener 7 u 8 dígitos, sin puntos.');
                    }
                },
            ],
            'condicion_iva' => ['required', Rule::in(array_keys(EmpresaController::CONDICIONES_IVA))],
            'contacto' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'localidad' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $datos['activo'] = $request->boolean('activo');

        return $datos;
    }

    protected function verificarMaestro(): void
    {
        if ($sistema = Cliente::maestroExterno()) {
            abort(redirect()->route('salidas.clientes.index')
                ->with('warning', "Los clientes se administran en {$sistema} y se sincronizan solos."));
        }
    }
}
