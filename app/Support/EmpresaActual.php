<?php

namespace App\Support;

use App\Models\Empresa;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;

/**
 * Resuelve la empresa "actual" del request.
 * - Usuario de empresa: siempre su empresa.
 * - Superadmin: la que eligió en Admin > Empresas (guardada en sesión); sin elegir = null (ve todo).
 * - Seeders, comandos y jobs: EmpresaActual::usar($id, fn () => ...).
 */
class EmpresaActual
{
    public const SESION = 'empresa_actual_id';

    protected static ?int $forzada = null;

    protected static ?Empresa $cache = null;

    public static function id(): ?int
    {
        if (static::$forzada !== null) {
            return static::$forzada;
        }

        $user = Auth::user();

        if (! $user) {
            return null;
        }

        if ($user->esSuperadmin()) {
            $id = static::sesion()?->get(self::SESION);

            return $id ? (int) $id : null;
        }

        return $user->empresa_id;
    }

    public static function modelo(): ?Empresa
    {
        $id = static::id();

        if ($id === null) {
            return null;
        }

        if (static::$cache?->id !== $id) {
            static::$cache = Empresa::find($id);
        }

        return static::$cache;
    }

    /**
     * Superadmin: elegir (o soltar con null) la empresa con la que opera.
     */
    public static function seleccionar(?int $empresaId): void
    {
        $sesion = static::sesion();

        if ($empresaId === null) {
            $sesion?->forget(self::SESION);
        } else {
            $sesion?->put(self::SESION, $empresaId);
        }

        static::$cache = null;
    }

    /**
     * Ejecuta un bloque como si la empresa actual fuera $empresaId.
     */
    public static function usar(int $empresaId, callable $callback): mixed
    {
        $anterior = static::$forzada;
        static::$forzada = $empresaId;
        static::$cache = null;

        try {
            return $callback();
        } finally {
            static::$forzada = $anterior;
            static::$cache = null;
        }
    }

    protected static function sesion(): ?Session
    {
        $request = request();

        return $request->hasSession() ? $request->session() : null;
    }
}
