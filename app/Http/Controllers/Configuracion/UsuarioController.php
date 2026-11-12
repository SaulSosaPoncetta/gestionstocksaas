<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UsuarioController extends Controller
{
    public function index()
    {
        $usuarios = User::deEmpresa(EmpresaActual::id())
            ->with('depositos')
            ->orderBy('name')
            ->get();

        return view('configuracion.usuarios', compact('usuarios'));
    }

    public function create()
    {
        $usuario = new User(['rol' => User::ROL_OPERADOR, 'activo' => true, 'todos_los_depositos' => true]);
        $usuario->setRelation('depositos', collect());

        return view('configuracion.usuario-form', $this->datosFormulario($usuario));
    }

    public function store(Request $request)
    {
        [$datos, $depositos] = $this->validar($request);
        $datos['empresa_id'] = EmpresaActual::id();
        $datos['activo'] = $request->boolean('activo');

        DB::transaction(function () use ($datos, $depositos) {
            $usuario = User::create($datos);
            $usuario->depositos()->sync($depositos);
        });

        return redirect()->route('configuracion.usuarios.index')->with('success', 'Usuario creado.');
    }

    public function edit(User $usuario)
    {
        $this->autorizar($usuario);
        $usuario->load('depositos');

        return view('configuracion.usuario-form', $this->datosFormulario($usuario));
    }

    public function update(Request $request, User $usuario)
    {
        $this->autorizar($usuario);

        [$datos, $depositos] = $this->validar($request, $usuario);
        $datos['activo'] = $request->boolean('activo');

        if (empty($datos['password'])) {
            unset($datos['password']);
        }

        // Un admin no puede quitarse a sí mismo el rol ni desactivarse
        if ($usuario->is($request->user())) {
            $datos['rol'] = $usuario->rol;
            $datos['activo'] = true;
        }

        DB::transaction(function () use ($usuario, $datos, $depositos) {
            $usuario->update($datos);
            $usuario->depositos()->sync($datos['todos_los_depositos'] ? [] : $depositos);
        });

        return redirect()->route('configuracion.usuarios.index')->with('success', 'Usuario actualizado.');
    }

    public function toggle(Request $request, User $usuario)
    {
        $this->autorizar($usuario);

        if ($usuario->is($request->user())) {
            return back()->with('error', 'No podés desactivar tu propio usuario.');
        }

        $usuario->update(['activo' => ! $usuario->activo]);

        return back()->with('success', $usuario->activo ? 'Usuario activado.' : 'Usuario desactivado.');
    }

    /**
     * @return array{0: array, 1: int[]}
     */
    protected function validar(Request $request, ?User $usuario = null): array
    {
        $empresaId = EmpresaActual::id();

        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'rol' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::min(8)],
            'depositos' => ['nullable', 'array'],
            'depositos.*' => [
                'integer',
                Rule::exists('depositos', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at'),
            ],
        ]);

        $depositos = array_map('intval', $datos['depositos'] ?? []);
        unset($datos['depositos']);

        // Los administradores operan siempre todos los depósitos
        $rol = ($usuario && $usuario->is($request->user())) ? $usuario->rol : $datos['rol'];
        $datos['todos_los_depositos'] = $rol === User::ROL_ADMIN || $request->boolean('todos_los_depositos');

        if (! $datos['todos_los_depositos'] && ! $depositos) {
            throw ValidationException::withMessages([
                'depositos' => 'Elegí al menos un depósito o marcá "Todos los depósitos".',
            ]);
        }

        return [$datos, $datos['todos_los_depositos'] ? [] : $depositos];
    }

    protected function autorizar(User $usuario): void
    {
        abort_unless($usuario->empresa_id === EmpresaActual::id(), 404);
    }

    protected function datosFormulario(User $usuario): array
    {
        return [
            'usuario' => $usuario,
            'roles' => User::ROLES,
            'depositos' => Deposito::activos()->orderByDesc('es_principal')->orderBy('nombre')->get(),
        ];
    }
}
