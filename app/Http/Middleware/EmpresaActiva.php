<?php

namespace App\Http\Middleware;

use App\Support\EmpresaActual;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica que el usuario esté activo y que su empresa tenga la suscripción vigente.
 * El superadmin pasa, pero tiene que haber elegido una empresa para operar.
 */
class EmpresaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->activo) {
            return $this->expulsar($request, 'Tu usuario está desactivado.');
        }

        if ($user->esSuperadmin()) {
            if (EmpresaActual::id() === null) {
                return redirect()->route('admin.empresas.index')
                    ->with('warning', 'Elegí una empresa para operar.');
            }

            return $next($request);
        }

        $empresa = $user->empresa;

        if (! $empresa || ! $empresa->suscripcionVigente()) {
            return $this->expulsar($request, 'La suscripción de tu empresa no está vigente. Comunicate con MiGestión.');
        }

        return $next($request);
    }

    protected function expulsar(Request $request, string $mensaje): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $mensaje]);
    }
}
