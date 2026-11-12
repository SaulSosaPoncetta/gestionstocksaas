<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Existencia;
use App\Models\MovimientoItem;
use App\Models\Ubicacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UbicacionController extends Controller
{
    public const MAX_GENERAR = 2000;

    public function index(Deposito $deposito)
    {
        return view('inventario.ubicaciones', [
            'deposito' => $deposito,
            'ubicaciones' => Ubicacion::arbol($deposito->id),
            'tipos' => Ubicacion::TIPOS,
        ]);
    }

    public function store(Request $request, Deposito $deposito)
    {
        $datos = $this->validar($request, $deposito);
        $datos['deposito_id'] = $deposito->id;

        Ubicacion::create($datos);
        $this->activarUbicaciones($deposito);

        return redirect()->route('inventario.ubicaciones.index', $deposito)->with('success', 'Ubicación creada.');
    }

    public function update(Request $request, Deposito $deposito, Ubicacion $ubicacion)
    {
        $this->verificarPertenencia($deposito, $ubicacion);

        $datos = $this->validar($request, $deposito, $ubicacion);

        if ($datos['padre_id'] !== null) {
            $prohibidas = array_merge([$ubicacion->id], $ubicacion->descendientesIds());

            if (in_array((int) $datos['padre_id'], $prohibidas, true)) {
                throw ValidationException::withMessages([
                    'padre_id' => 'Una ubicación no puede quedar dentro de sí misma ni de una de sus hijas.',
                ]);
            }
        }

        $ubicacion->update($datos);

        return redirect()->route('inventario.ubicaciones.index', $deposito)->with('success', 'Ubicación actualizada.');
    }

    public function destroy(Deposito $deposito, Ubicacion $ubicacion)
    {
        $this->verificarPertenencia($deposito, $ubicacion);

        if ($ubicacion->hijas()->exists()) {
            return back()->with('error', "No se puede eliminar {$ubicacion->codigo}: tiene ubicaciones dentro.");
        }

        if (Existencia::where('ubicacion_id', $ubicacion->id)->where('cantidad', '!=', 0)->exists()) {
            return back()->with('error', "No se puede eliminar {$ubicacion->codigo}: tiene stock. Movelo a otra ubicación primero.");
        }

        if (MovimientoItem::where('ubicacion_id', $ubicacion->id)->exists()) {
            return back()->with('error', "No se puede eliminar {$ubicacion->codigo}: tiene movimientos registrados. Podés desactivarla.");
        }

        Existencia::where('ubicacion_id', $ubicacion->id)->delete();
        $ubicacion->delete();

        return redirect()->route('inventario.ubicaciones.index', $deposito)->with('success', 'Ubicación eliminada.');
    }

    /**
     * Crea en un paso una zona con sus estantes y posiciones.
     * Ej.: zona A, estantes 1 a 10, 4 niveles → A, A-01…A-10, A-01-1…A-10-4
     */
    public function generar(Request $request, Deposito $deposito)
    {
        $datos = $request->validate([
            'zona_codigo' => ['required', 'string', 'max:10', 'alpha_dash'],
            'zona_nombre' => ['nullable', 'string', 'max:100'],
            'estante_desde' => ['required', 'integer', 'min:1', 'max:999'],
            'estante_hasta' => ['required', 'integer', 'gte:estante_desde', 'max:999'],
            'niveles' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        $zonaCodigo = mb_strtoupper($datos['zona_codigo']);
        $cantidadEstantes = $datos['estante_hasta'] - $datos['estante_desde'] + 1;
        $total = 1 + $cantidadEstantes + $cantidadEstantes * $datos['niveles'];

        if ($total > self::MAX_GENERAR) {
            throw ValidationException::withMessages([
                'niveles' => "Eso genera {$total} ubicaciones; el máximo por vez es " . self::MAX_GENERAR . '.',
            ]);
        }

        $creadas = DB::transaction(function () use ($deposito, $datos, $zonaCodigo) {
            $existentes = Ubicacion::where('deposito_id', $deposito->id)->pluck('id', 'codigo');
            $creadas = 0;

            $crear = function (string $codigo, string $tipo, ?int $padreId, ?string $nombre = null) use ($deposito, &$existentes, &$creadas) {
                if ($existentes->has($codigo)) {
                    return $existentes->get($codigo);
                }

                $ubicacion = Ubicacion::create([
                    'deposito_id' => $deposito->id,
                    'padre_id' => $padreId,
                    'tipo' => $tipo,
                    'codigo' => $codigo,
                    'nombre' => $nombre,
                    'activa' => true,
                ]);

                $existentes->put($codigo, $ubicacion->id);
                $creadas++;

                return $ubicacion->id;
            };

            $zonaId = $crear($zonaCodigo, 'zona', null, $datos['zona_nombre'] ?: "Zona {$zonaCodigo}");
            $digitos = max(2, strlen((string) $datos['estante_hasta']));

            for ($e = $datos['estante_desde']; $e <= $datos['estante_hasta']; $e++) {
                $estanteCodigo = $zonaCodigo . '-' . str_pad((string) $e, $digitos, '0', STR_PAD_LEFT);
                $estanteId = $crear($estanteCodigo, 'estante', $zonaId);

                for ($n = 1; $n <= $datos['niveles']; $n++) {
                    $crear("{$estanteCodigo}-{$n}", 'posicion', $estanteId);
                }
            }

            return $creadas;
        });

        $this->activarUbicaciones($deposito);

        return redirect()->route('inventario.ubicaciones.index', $deposito)
            ->with('success', $creadas ? "Se crearon {$creadas} ubicaciones." : 'No había ubicaciones nuevas para crear.');
    }

    protected function validar(Request $request, Deposito $deposito, ?Ubicacion $ubicacion = null): array
    {
        $datos = $request->validate([
            'codigo' => [
                'required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-]+$/',
                Rule::unique('ubicaciones', 'codigo')->where('deposito_id', $deposito->id)->ignore($ubicacion?->id),
            ],
            'nombre' => ['nullable', 'string', 'max:100'],
            'tipo' => ['required', Rule::in(array_keys(Ubicacion::TIPOS))],
            'padre_id' => [
                'nullable', 'integer',
                Rule::exists('ubicaciones', 'id')->where('deposito_id', $deposito->id),
            ],
        ], [
            'codigo.regex' => 'El código solo puede tener letras, números, punto, guion y guion bajo.',
        ]);

        $datos['codigo'] = mb_strtoupper($datos['codigo']);
        $datos['padre_id'] = $datos['padre_id'] ?? null;
        $datos['activa'] = $request->boolean('activa');

        return $datos;
    }

    protected function verificarPertenencia(Deposito $deposito, Ubicacion $ubicacion): void
    {
        abort_unless($ubicacion->deposito_id === $deposito->id, 404);
    }

    protected function activarUbicaciones(Deposito $deposito): void
    {
        if (! $deposito->usa_ubicaciones) {
            $deposito->update(['usa_ubicaciones' => true]);
        }
    }
}
