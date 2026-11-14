<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Administración del SaaS (solo superadmin).
 * Más adelante el alta de empresas va a llegar también desde MiGestión Panel por API.
 */
class EmpresaAdminController extends Controller
{
    public function index()
    {
        return view('admin.empresas', [
            'empresas' => Empresa::withCount('usuarios')->orderBy('razon_social')->get(),
            'condiciones' => EmpresaController::CONDICIONES_IVA,
            'modulos' => Empresa::MODULOS,
            'actualId' => EmpresaActual::id(),
        ]);
    }

    public function store(Request $request)
    {
        $request->merge(['cuit' => Empresa::normalizarCuit((string) $request->input('cuit'))]);

        $datos = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_fantasia' => ['nullable', 'string', 'max:255'],
            'cuit' => [
                'required',
                'regex:/^\d{2}-\d{8}-\d$/',
                'unique:empresas,cuit',
                function ($atributo, $valor, $fallar) {
                    if (! self::cuitValido($valor)) {
                        $fallar('El CUIT no es válido (dígito verificador incorrecto).');
                    }
                },
            ],
            'condicion_iva' => ['required', Rule::in(array_keys(EmpresaController::CONDICIONES_IVA))],
            'vence_el' => ['nullable', 'date'],
            'admin_nombre' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', Password::min(8)],
        ]);

        $empresa = DB::transaction(function () use ($datos) {
            $empresa = Empresa::create([
                'razon_social' => $datos['razon_social'],
                'nombre_fantasia' => $datos['nombre_fantasia'] ?? null,
                'cuit' => $datos['cuit'],
                'condicion_iva' => $datos['condicion_iva'],
                'vence_el' => $datos['vence_el'] ?? null,
                'activa' => true,
            ]);

            User::create([
                'empresa_id' => $empresa->id,
                'name' => $datos['admin_nombre'],
                'email' => $datos['admin_email'],
                'password' => $datos['admin_password'],
                'rol' => User::ROL_ADMIN,
                'activo' => true,
            ]);

            return $empresa;
        });

        return redirect()->route('admin.empresas.index')
            ->with('success', "Empresa {$empresa->nombre} creada con su administrador.");
    }

    /** Habilita o quita módulos según el plan contratado (más adelante lo hace MiGestión Panel por API). */
    public function modulos(Request $request, Empresa $empresa)
    {
        $modulos = [];

        foreach (array_keys(Empresa::MODULOS) as $modulo) {
            $modulos[$modulo] = $request->boolean("modulos.{$modulo}");
        }

        $empresa->update(['modulos' => $modulos]);

        return back()->with('success', "Módulos de {$empresa->nombre} actualizados.");
    }

    public function entrar(Empresa $empresa)
    {
        EmpresaActual::seleccionar($empresa->id);

        return redirect()->route('dashboard')->with('success', "Operando como {$empresa->nombre}.");
    }

    public function salir()
    {
        EmpresaActual::seleccionar(null);

        return redirect()->route('admin.empresas.index');
    }

    public static function cuitValido(string $cuit): bool
    {
        $n = preg_replace('/\D/', '', $cuit);

        if (strlen($n) !== 11) {
            return false;
        }

        $multiplicadores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;

        for ($i = 0; $i < 10; $i++) {
            $suma += (int) $n[$i] * $multiplicadores[$i];
        }

        $verificador = 11 - ($suma % 11);
        $verificador = match ($verificador) {
            11 => 0,
            10 => 9,
            default => $verificador,
        };

        return $verificador === (int) $n[10];
    }
}
