<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UsuarioController extends Controller
{
    public function index()
    {
        $usuarios = User::deEmpresa(EmpresaActual::id())->orderBy('name')->get();

        return view('configuracion.usuarios', compact('usuarios'));
    }

    public function create()
    {
        return view('configuracion.usuario-form', [
            'usuario' => new User(['rol' => User::ROL_OPERADOR, 'activo' => true]),
            'roles' => User::ROLES,
        ]);
    }

    public function store(Request $request)
    {
        $datos = $this->validar($request);
        $datos['empresa_id'] = EmpresaActual::id();
        $datos['activo'] = $request->boolean('activo');

        User::create($datos);

        return redirect()->route('configuracion.usuarios.index')->with('success', 'Usuario creado.');
    }

    public function edit(User $usuario)
    {
        $this->autorizar($usuario);

        return view('configuracion.usuario-form', [
            'usuario' => $usuario,
            'roles' => User::ROLES,
        ]);
    }

    public function update(Request $request, User $usuario)
    {
        $this->autorizar($usuario);

        $datos = $this->validar($request, $usuario);
        $datos['activo'] = $request->boolean('activo');

        if (empty($datos['password'])) {
            unset($datos['password']);
        }

        // Un admin no puede quitarse a sí mismo el rol ni desactivarse
        if ($usuario->is($request->user())) {
            $datos['rol'] = $usuario->rol;
            $datos['activo'] = true;
        }

        $usuario->update($datos);

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

    protected function validar(Request $request, ?User $usuario = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'rol' => ['required', Rule::in(array_keys(User::ROLES))],
            'password' => [$usuario ? 'nullable' : 'required', 'confirmed', Password::min(8)],
        ]);
    }

    protected function autorizar(User $usuario): void
    {
        abort_unless($usuario->empresa_id === EmpresaActual::id(), 404);
    }
}
