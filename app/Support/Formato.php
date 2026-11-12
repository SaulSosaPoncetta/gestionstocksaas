<?php

namespace App\Support;

/**
 * Formatos argentinos para mostrar en pantalla.
 * En Blade: @use('App\Support\Formato') y luego {{ Formato::cantidad($x) }}
 */
class Formato
{
    /** Cantidad con los decimales de la empresa, sin ceros sobrantes: 1.500 · 12,5 · -3 */
    public static function cantidad(mixed $valor, ?int $decimales = null): string
    {
        $decimales ??= EmpresaActual::modelo()?->decimales_cantidad ?? 2;
        $numero = (float) $valor;

        if (abs($numero) < 0.00005) {
            $numero = 0.0;
        }

        $texto = number_format($numero, $decimales, ',', '.');

        if ($decimales > 0) {
            $texto = rtrim(rtrim($texto, '0'), ',');
        }

        return $texto;
    }

    /** Cantidad con signo para movimientos: +10 · −3 */
    public static function cantidadConSigno(mixed $valor): string
    {
        $numero = (float) $valor;

        return ($numero > 0 ? '+' : ($numero < 0 ? '−' : '')) . static::cantidad(abs($numero));
    }

    public static function moneda(mixed $valor, int $decimales = 2): string
    {
        return '$ ' . number_format((float) $valor, $decimales, ',', '.');
    }
}
