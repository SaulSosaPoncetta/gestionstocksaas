<?php

namespace App\Http\Middleware;

use App\Models\Empresa;
use App\Support\EmpresaActual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uso en rutas: ->middleware('modulo:compras')
 * Corta el acceso si la empresa no tiene contratado el módulo.
 */
class TieneModulo
{
    public function handle(Request $request, Closure $next, string $modulo): Response
    {
        $empresa = EmpresaActual::modelo();

        if (! $empresa || ! $empresa->tieneModulo($modulo)) {
            $nombre = Empresa::MODULOS[$modulo] ?? $modulo;

            if ($request->expectsJson()) {
                abort(403, "Tu plan no incluye el módulo: {$nombre}.");
            }

            return redirect()->route('dashboard')
                ->with('warning', "Tu plan no incluye el módulo \"{$nombre}\". Consultá con MiGestión para sumarlo.");
        }

        return $next($request);
    }
}
