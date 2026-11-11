<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $clave = Str::transliterate(Str::lower($datos['email']) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($clave, 5)) {
            $segundos = RateLimiter::availableIn($clave);

            throw ValidationException::withMessages([
                'email' => "Demasiados intentos. Probá de nuevo en {$segundos} segundos.",
            ]);
        }

        if (! Auth::attempt($datos + ['activo' => true], $request->boolean('recordarme'))) {
            RateLimiter::hit($clave, 60);

            throw ValidationException::withMessages([
                'email' => 'Email o contraseña incorrectos, o el usuario está desactivado.',
            ]);
        }

        RateLimiter::clear($clave);
        $request->session()->regenerate();

        $user = $request->user();
        $user->forceFill(['ultimo_acceso' => now()])->saveQuietly();

        if ($user->esSuperadmin()) {
            return redirect()->route('admin.empresas.index');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
