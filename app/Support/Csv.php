<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exportación a CSV pensada para abrir directo en Excel en español (Argentina):
 * separador ";" , decimales con coma y BOM UTF-8 para que respete los acentos.
 */
class Csv
{
    /**
     * @param  array     $encabezados  ['Columna 1', 'Columna 2', …]
     * @param  iterable  $filas        cada fila es un array de valores (números, textos o null)
     */
    public static function descargar(string $nombre, array $encabezados, iterable $filas): StreamedResponse
    {
        $archivo = preg_replace('/[^A-Za-z0-9_\-]+/', '-', $nombre) . '.csv';

        return response()->streamDownload(function () use ($encabezados, $filas) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $encabezados, ';');

            foreach ($filas as $fila) {
                fputcsv($salida, array_map([self::class, 'valor'], $fila), ';');
            }

            fclose($salida);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected static function valor(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        if (is_float($valor) || is_int($valor)) {
            return str_replace('.', ',', (string) round($valor, 4));
        }

        $texto = (string) $valor;

        // Evita que Excel interprete fórmulas en textos cargados por usuarios
        return preg_match('/^[=+\-@]/', $texto) ? "'" . $texto : $texto;
    }
}
