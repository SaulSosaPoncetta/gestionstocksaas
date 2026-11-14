<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Existencia;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DepositoController extends Controller
{
    public function index(Request $request)
    {
        $depositos = Deposito::visibles()->with('responsable')
            ->withCount('ubicaciones')
            ->when($request->query('estado') !== 'todos', fn ($q) => $q->where('activo', true))
            ->orderByDesc('es_principal')
            ->orderBy('nombre')
            ->get();

        return view('inventario.depositos', [
            'depositos' => $depositos,
            'verTodos' => $request->query('estado') === 'todos',
        ]);
    }

    public function create()
    {
        return view('inventario.deposito-form', $this->datosFormulario(new Deposito([
            'codigo' => $this->siguienteCodigo(),
            'tipo' => 'deposito',
            'activo' => true,
        ])));
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);

        $deposito = DB::transaction(function () use ($datos, $request) {
            $deposito = Deposito::create($datos);

            if ($request->boolean('es_principal')) {
                $deposito->marcarComoPrincipal();
            }

            return $deposito;
        });

        return redirect()->route('inventario.depositos.index')->with('success', "Depósito {$deposito->nombre} creado.");
    }

    public function edit(Deposito $deposito)
    {
        abort_if($deposito->es_transito, 404);

        return view('inventario.deposito-form', $this->datosFormulario($deposito));
    }

    public function update(Request $request, Deposito $deposito)
    {
        abort_if($deposito->es_transito, 404);

        $datos = $this->validar($request, $deposito);

        if ($deposito->es_principal && ! $datos['activo']) {
            return back()->withInput()->with('error', 'No se puede desactivar el depósito principal. Marcá otro como principal primero.');
        }

        DB::transaction(function () use ($deposito, $datos, $request) {
            $deposito->update($datos);

            if ($request->boolean('es_principal') && ! $deposito->es_principal) {
                $deposito->marcarComoPrincipal();
            }
        });

        return redirect()->route('inventario.depositos.index')->with('success', 'Depósito actualizado.');
    }

    public function principal(Deposito $deposito)
    {
        abort_if($deposito->es_transito, 404);

        $deposito->marcarComoPrincipal();

        return back()->with('success', "{$deposito->nombre} es ahora el depósito principal.");
    }

    public function destroy(Deposito $deposito)
    {
        abort_if($deposito->es_transito, 404);

        if ($deposito->es_principal) {
            return back()->with('error', 'No se puede eliminar el depósito principal.');
        }

        if (Existencia::where('deposito_id', $deposito->id)->where('cantidad', '!=', 0)->exists()) {
            return back()->with('error', "No se puede eliminar {$deposito->nombre}: tiene stock. Transferilo o ajustalo a cero primero.");
        }

        $deposito->delete();

        return redirect()->route('inventario.depositos.index')->with('success', 'Depósito eliminado.');
    }

    protected function validar(Request $request, ?Deposito $deposito = null): array
    {
        $empresaId = EmpresaActual::id();

        $datos = $request->validate([
            'codigo' => [
                'required', 'string', 'max:20', 'alpha_dash',
                Rule::unique('depositos', 'codigo')->where('empresa_id', $empresaId)->ignore($deposito?->id),
            ],
            'nombre' => ['required', 'string', 'max:100'],
            'tipo' => ['required', Rule::in(array_keys(Deposito::TIPOS))],
            'direccion' => ['nullable', 'string', 'max:255'],
            'localidad' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'responsable_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('empresa_id', $empresaId)],
            'permite_stock_negativo' => ['nullable', Rule::in(['0', '1'])],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ]);

        $datos['codigo'] = mb_strtoupper($datos['codigo']);
        $datos['usa_ubicaciones'] = $request->boolean('usa_ubicaciones');
        $datos['activo'] = $request->boolean('activo');
        $datos['permite_stock_negativo'] = match ($datos['permite_stock_negativo'] ?? null) {
            '1' => true,
            '0' => false,
            default => null,
        };

        return $datos;
    }

    protected function siguienteCodigo(): string
    {
        $n = Deposito::withTrashed()->count() + 1;

        do {
            $codigo = 'DEP' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $n++;
        } while (Deposito::withTrashed()->where('codigo', $codigo)->exists());

        return $codigo;
    }

    protected function datosFormulario(Deposito $deposito): array
    {
        return [
            'deposito' => $deposito,
            'tipos' => Deposito::TIPOS,
            'usuarios' => User::deEmpresa(EmpresaActual::id())->activos()->orderBy('name')->get(),
            'empresa' => EmpresaActual::modelo(),
        ];
    }
}
