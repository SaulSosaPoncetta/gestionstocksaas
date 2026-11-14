<?php

namespace App\Http\Controllers\Configuracion;

use App\Http\Controllers\Controller;
use App\Models\Deposito;
use App\Models\Integracion;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;

class IntegracionController extends Controller
{
    /**
     * Campos configurables de cada integración (se dibujan en la vista).
     * Tipos: select, bool, deposito.
     */
    public const CAMPOS = [
        'comercial' => [
            'deposito_id' => [
                'label' => 'Depósito por defecto (si Comercial no informa sucursal)',
                'tipo' => 'deposito',
            ],
            'maestro_productos' => [
                'label' => 'El alta de productos la maneja',
                'tipo' => 'select',
                'opciones' => ['stock' => 'GestiónStock', 'comercial' => 'GestiónComercial'],
            ],
            'maestro_proveedores' => [
                'label' => 'El alta de proveedores la maneja',
                'tipo' => 'select',
                'opciones' => ['stock' => 'GestiónStock', 'comercial' => 'GestiónComercial'],
            ],
            'maestro_clientes' => [
                'label' => 'El alta de clientes la maneja',
                'tipo' => 'select',
                'opciones' => ['stock' => 'GestiónStock', 'comercial' => 'GestiónComercial'],
            ],
            'descontar_al' => [
                'label' => 'Descontar stock al emitir',
                'tipo' => 'select',
                'opciones' => ['factura' => 'Factura', 'remito' => 'Remito'],
            ],
            'ingresar_al' => [
                'label' => 'Ingresar stock al registrar',
                'tipo' => 'select',
                'opciones' => ['recepcion' => 'Recepción de mercadería', 'factura_compra' => 'Factura de compra'],
            ],
        ],
        'mercado' => [
            'deposito_id' => [
                'label' => 'Depósito desde el que vende la tienda',
                'tipo' => 'deposito',
            ],
            'publicar' => [
                'label' => 'Stock a publicar en la tienda',
                'tipo' => 'select',
                'opciones' => ['disponible' => 'Disponible (real menos reservado)', 'real' => 'Stock real'],
            ],
            'reservar_al_pedido' => [
                'label' => 'Reservar stock cuando entra un pedido',
                'tipo' => 'bool',
            ],
        ],
        'contable' => [
            'enviar_asientos' => [
                'label' => 'Enviar asientos automáticamente',
                'tipo' => 'bool',
            ],
            'agrupar' => [
                'label' => 'Agrupar asientos',
                'tipo' => 'select',
                'opciones' => ['diario' => 'Un asiento por día', 'por_comprobante' => 'Uno por comprobante'],
            ],
        ],
        'panel' => [],
    ];

    public function index()
    {
        $actuales = EmpresaActual::modelo()->integraciones()->get()->keyBy('sistema');

        return view('configuracion.integraciones', [
            'sistemas' => Integracion::sistemasDisponibles(),
            'actuales' => $actuales,
        ]);
    }

    public function edit(Request $request, string $sistema)
    {
        $this->verificarPermiso($request, $sistema);

        return view('configuracion.integracion-editar', [
            'sistema' => $sistema,
            'config' => config("ecosistema.sistemas.{$sistema}"),
            'integracion' => $this->obtener($sistema),
            'campos' => self::CAMPOS[$sistema] ?? [],
            'empresa' => EmpresaActual::modelo(),
            'depositos' => Deposito::activos()->orderByDesc('es_principal')->orderBy('nombre')->get(),
        ]);
    }

    public function update(Request $request, string $sistema)
    {
        $this->verificarPermiso($request, $sistema);

        $datos = $request->validate([
            'url_base' => ['nullable', 'url', 'max:255'],
            'id_empresa_remota' => ['nullable', 'string', 'max:100'],
            'token_saliente' => ['nullable', 'string', 'max:500'],
        ]);

        $integracion = $this->obtener($sistema);

        $opciones = $integracion->opciones ?? [];

        foreach (self::CAMPOS[$sistema] ?? [] as $clave => $campo) {
            if ($campo['tipo'] === 'bool') {
                $opciones[$clave] = $request->boolean("opciones.{$clave}");
            } elseif ($campo['tipo'] === 'deposito') {
                $depositoId = $request->integer("opciones.{$clave}");
                $opciones[$clave] = $depositoId && Deposito::activos()->whereKey($depositoId)->exists() ? $depositoId : null;
            } else {
                $valor = $request->input("opciones.{$clave}");
                if (is_string($valor) && array_key_exists($valor, $campo['opciones'])) {
                    $opciones[$clave] = $valor;
                }
            }
        }

        $integracion->url_base = $datos['url_base'] ?? null;
        $integracion->id_empresa_remota = $datos['id_empresa_remota'] ?? null;
        $integracion->opciones = $opciones;
        $integracion->activa = $request->boolean('activa');

        if (! empty($datos['token_saliente'])) {
            $integracion->token_saliente = $datos['token_saliente'];
        }

        $integracion->save();

        $redireccion = redirect()->route('configuracion.integraciones.edit', $sistema)
            ->with('success', 'Integración guardada.');

        // Al activarla por primera vez se generan las credenciales
        if ($integracion->activa && ! $integracion->token_entrante_hash) {
            $redireccion->with('token_generado', $integracion->generarTokenEntrante());
        }

        if ($integracion->activa && ! $integracion->webhook_secreto) {
            $redireccion->with('secreto_generado', $integracion->generarSecretoWebhook());
        }

        return $redireccion;
    }

    public function token(Request $request, string $sistema)
    {
        $this->verificarPermiso($request, $sistema);

        $integracion = $this->obtener($sistema);
        if (! $integracion->exists) {
            $integracion->save();
        }

        return redirect()->route('configuracion.integraciones.edit', $sistema)
            ->with('token_generado', $integracion->generarTokenEntrante())
            ->with('warning', 'Se generó un token nuevo. El anterior dejó de funcionar.');
    }

    public function secreto(Request $request, string $sistema)
    {
        $this->verificarPermiso($request, $sistema);

        $integracion = $this->obtener($sistema);
        if (! $integracion->exists) {
            $integracion->save();
        }

        return redirect()->route('configuracion.integraciones.edit', $sistema)
            ->with('secreto_generado', $integracion->generarSecretoWebhook())
            ->with('warning', 'Se generó una clave de firma nueva. Actualizala en el otro sistema.');
    }

    protected function obtener(string $sistema): Integracion
    {
        return Integracion::firstOrNew(
            ['sistema' => $sistema],
            ['empresa_id' => EmpresaActual::id(), 'activa' => false]
        );
    }

    /**
     * La integración con el Panel la administra solo MiGestión.
     */
    protected function verificarPermiso(Request $request, string $sistema): void
    {
        if ($sistema === 'panel') {
            abort_unless($request->user()->esSuperadmin(), 403, 'Esta integración la administra MiGestión.');
        }
    }
}
