<?php

namespace App\Http\Controllers\Inventario;

use App\Http\Controllers\Controller;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\Producto;
use App\Models\ProductoPresentacion;
use App\Models\Ubicacion;
use App\Models\User;
use App\Services\StockService;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MovimientoController extends Controller
{
    /**
     * Movimientos que se cargan a mano.
     * modo: entrada | salida | ajuste
     * pide_costo: true | false | 'motivo' (depende del motivo elegido)
     */
    public const TIPOS_MANUALES = [
        Movimiento::TIPO_INGRESO => [
            'titulo' => 'Ingreso de mercadería',
            'descripcion' => 'Entradas de stock: compras, devoluciones de clientes, producción propia…',
            'icono' => 'bi-plus-circle',
            'roles' => ['admin', 'encargado', 'operador'],
            'modo' => 'entrada',
            'pide_costo' => 'motivo',
            'usa_motivo' => true,
        ],
        Movimiento::TIPO_EGRESO => [
            'titulo' => 'Egreso de mercadería',
            'descripcion' => 'Salidas de stock: consumo interno, roturas, vencimientos, ventas sin sistema de facturación…',
            'icono' => 'bi-dash-circle',
            'roles' => ['admin', 'encargado', 'operador'],
            'modo' => 'salida',
            'pide_costo' => false,
            'usa_motivo' => true,
        ],
        Movimiento::TIPO_AJUSTE => [
            'titulo' => 'Ajuste de stock',
            'descripcion' => 'Corregí el stock cargando lo que contaste, o directamente la diferencia.',
            'icono' => 'bi-sliders',
            'roles' => ['admin', 'encargado'],
            'modo' => 'ajuste',
            'pide_costo' => false,
            'usa_motivo' => true,
        ],
        Movimiento::TIPO_INICIAL => [
            'titulo' => 'Carga de stock inicial',
            'descripcion' => 'Para arrancar: cargá lo que hay hoy en el depósito con su costo.',
            'icono' => 'bi-box-arrow-in-down',
            'roles' => ['admin', 'encargado'],
            'modo' => 'entrada',
            'pide_costo' => true,
            'usa_motivo' => false,
        ],
    ];

    public function __construct(protected StockService $stock)
    {
    }

    public function index(Request $request)
    {
        $usuario = $request->user();
        $depositos = $usuario->depositosPermitidos();

        $filtros = [
            'q' => trim((string) $request->query('q')),
            'tipo' => array_key_exists((string) $request->query('tipo'), Movimiento::TIPOS) ? $request->query('tipo') : null,
            'motivo_id' => $request->integer('motivo_id') ?: null,
            'deposito_id' => $request->integer('deposito_id') ?: null,
            'estado' => in_array($request->query('estado'), [Movimiento::ESTADO_CONFIRMADO, Movimiento::ESTADO_ANULADO], true) ? $request->query('estado') : null,
            'desde' => $this->fecha($request->query('desde')),
            'hasta' => $this->fecha($request->query('hasta')),
        ];

        $movimientos = Movimiento::query()
            ->with(['deposito', 'depositoDestino', 'usuario', 'motivo'])
            ->withCount('items')
            ->accesiblesPara($usuario)
            ->when($filtros['q'] !== '', function ($q) use ($filtros) {
                $texto = $filtros['q'];
                $q->where(function ($w) use ($texto) {
                    $w->where('comprobante', 'like', "%{$texto}%")
                        ->orWhere('referencia_externa', 'like', "%{$texto}%")
                        ->orWhere('observaciones', 'like', "%{$texto}%");

                    if (ctype_digit($texto)) {
                        $w->orWhere('numero', (int) $texto);
                    }
                });
            })
            ->when($filtros['tipo'], fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($filtros['motivo_id'], fn ($q, $id) => $q->where('motivo_id', $id))
            ->when($filtros['estado'], fn ($q, $estado) => $q->where('estado', $estado))
            ->when($filtros['deposito_id'], fn ($q, $id) => $q->where(fn ($w) => $w->where('deposito_id', $id)->orWhere('deposito_destino_id', $id)))
            ->when($filtros['desde'], fn ($q, $d) => $q->where('fecha', '>=', $d->copy()->startOfDay()))
            ->when($filtros['hasta'], fn ($q, $h) => $q->where('fecha', '<=', $h->copy()->endOfDay()))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $filtros['desde'] = $filtros['desde']?->toDateString();
        $filtros['hasta'] = $filtros['hasta']?->toDateString();

        return view('inventario.movimientos', [
            'movimientos' => $movimientos,
            'filtros' => $filtros,
            'depositos' => $depositos,
            'tipos' => Movimiento::TIPOS,
            'motivos' => Motivo::orderBy('tipo')->orderBy('orden')->orderBy('nombre')->get()->groupBy('tipo'),
            'tiposManuales' => $this->tiposPermitidos($usuario),
        ]);
    }

    public function show(Request $request, Movimiento $movimiento)
    {
        abort_unless($movimiento->puedeVerlo($request->user()), 404);

        $movimiento->load([
            'items.producto.unidad',
            'items.deposito',
            'items.ubicacion',
            'items.presentacion',
            'items.lote',
            'items.series',
            'motivo',
            'transferencia',
            'recepcionCompra.orden',
            'deposito',
            'depositoDestino',
            'usuario',
            'anulaA',
            'anuladoPor',
            'usuarioAnulacion',
        ]);

        $usuario = $request->user();

        return view('inventario.movimiento', [
            'movimiento' => $movimiento,
            'puedeAnular' => $movimiento->puedeAnularseManualmente()
                && $usuario->tieneRol('admin', 'encargado')
                && $usuario->puedeOperarDeposito($movimiento->deposito_id)
                && (! $movimiento->deposito_destino_id || $usuario->puedeOperarDeposito($movimiento->deposito_destino_id)),
        ]);
    }

    public function create(Request $request, string $tipo)
    {
        $config = $this->autorizarTipo($request->user(), $tipo);
        $depositos = $request->user()->depositosPermitidos();

        if ($depositos->isEmpty()) {
            return redirect()->route('inventario.movimientos.index')->with('error', 'No tenés depósitos asignados para cargar movimientos.');
        }

        $motivos = $config['usa_motivo'] ? Motivo::activos()->deTipo($tipo)->get() : collect();

        if ($config['usa_motivo'] && $motivos->isEmpty()) {
            return redirect()->route('inventario.movimientos.index')
                ->with('error', 'No hay motivos activos para este tipo de movimiento. Cargalos en Configuración › Motivos.');
        }

        return view('inventario.movimiento-form', [
            'tipo' => $tipo,
            'config' => $config,
            'depositos' => $depositos,
            'depositoInicial' => (int) old('deposito_id', $depositos->firstWhere('es_principal', true)?->id ?? $depositos->first()->id),
            'motivos' => $motivos,
            'ubicaciones' => $this->ubicacionesPorDeposito($depositos),
            'lineasPrevias' => $this->lineasPrevias($request->old('lineas', [])),
        ]);
    }

    public function store(Request $request, string $tipo)
    {
        $usuario = $request->user();
        $config = $this->autorizarTipo($usuario, $tipo);
        $esAjuste = $config['modo'] === 'ajuste';
        $modo = $esAjuste ? $request->input('modo_ajuste') : null;

        $datos = $request->validate([
            'deposito_id' => ['required', 'integer'],
            'motivo_id' => [
                $config['usa_motivo'] ? 'required' : 'prohibited',
                'integer',
                Rule::exists('motivos', 'id')->where('tipo', $tipo)->where('activo', true)->where('empresa_id', EmpresaActual::id()),
            ],
            'modo_ajuste' => [$esAjuste ? 'required' : 'prohibited', Rule::in(['contado', 'diferencia'])],
            'comprobante' => ['nullable', 'string', 'max:100'],
            'fecha_comprobante' => ['nullable', 'date', 'before_or_equal:today'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'lineas' => ['required', 'array', 'min:1', 'max:500'],
            'lineas.*.producto_id' => ['required', 'integer'],
            'lineas.*.cantidad' => match (true) {
                $modo === 'contado' => ['required', 'numeric', 'min:0'],
                $modo === 'diferencia' => ['required', 'numeric', 'not_in:0'],
                default => ['required', 'numeric', 'gt:0'],
            },
            'lineas.*.costo_unitario' => ['nullable', 'numeric', 'min:0'],
            'lineas.*.ubicacion_id' => ['nullable', 'integer'],
            'lineas.*.presentacion_id' => ['nullable', 'integer'],
            'lineas.*.lote_id' => ['nullable', 'integer'],
            'lineas.*.lote_codigo' => ['nullable', 'string', 'max:60'],
            'lineas.*.lote_vencimiento' => ['nullable', 'date'],
            'lineas.*.series' => ['nullable', 'string', 'max:20000'],
        ], [
            'lineas.required' => 'Agregá al menos un producto.',
            'motivo_id.required' => 'Elegí el motivo.',
            'lineas.*.cantidad.not_in' => 'La diferencia no puede ser cero.',
        ], [
            'lineas.*.cantidad' => 'cantidad',
            'lineas.*.costo_unitario' => 'costo',
        ]);

        $motivo = isset($datos['motivo_id']) ? Motivo::find($datos['motivo_id']) : null;

        if ($motivo?->requiere_observacion && blank($datos['observaciones'] ?? null)) {
            throw ValidationException::withMessages([
                'observaciones' => "Para el motivo \"{$motivo->nombre}\" tenés que completar las observaciones.",
            ]);
        }

        // El costo solo se usa si el tipo o el motivo lo piden
        $pideCosto = $config['pide_costo'] === 'motivo' ? (bool) $motivo?->pide_costo : (bool) $config['pide_costo'];

        $lineas = $this->convertirPresentaciones($datos['lineas']);

        if (! $pideCosto) {
            $lineas = array_map(fn ($l) => array_diff_key($l, ['costo_unitario' => true]), $lineas);
        }

        $depositoId = (int) $datos['deposito_id'];
        $opciones = [
            'motivo_id' => $motivo?->id,
            'comprobante' => $datos['comprobante'] ?? null,
            'fecha_comprobante' => $datos['fecha_comprobante'] ?? null,
            'observaciones' => $datos['observaciones'] ?? null,
        ];

        $movimiento = match (true) {
            $tipo === Movimiento::TIPO_INICIAL => $this->stock->cargarInicial($depositoId, $lineas, $opciones, $usuario),
            $tipo === Movimiento::TIPO_INGRESO => $this->stock->ingresar($depositoId, $lineas, $opciones, $usuario),
            $tipo === Movimiento::TIPO_EGRESO => $this->stock->egresar($depositoId, $lineas, $opciones, $usuario),
            $modo === 'contado' => $this->stock->ajustarAContado(
                $depositoId,
                array_map(fn ($l) => ['contado' => $l['cantidad']] + $l, $lineas),
                $opciones,
                $usuario
            ),
            default => $this->stock->ajustar($depositoId, $lineas, $opciones, $usuario),
        };

        return redirect()->route('inventario.movimientos.show', $movimiento)
            ->with('success', "Movimiento N° {$movimiento->numero_texto} registrado.");
    }

    public function anular(Request $request, Movimiento $movimiento)
    {
        abort_unless($movimiento->puedeVerlo($request->user()), 404);

        $datos = $request->validate([
            'motivo' => ['required', 'string', 'max:255'],
        ], [
            'motivo.required' => 'Indicá el motivo de la anulación.',
        ]);

        try {
            $contra = $this->stock->anular($movimiento, $request->user(), $datos['motivo']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->implode(' '));
        }

        return redirect()->route('inventario.movimientos.show', $movimiento)
            ->with('success', "Movimiento anulado. Se generó el contramovimiento N° {$contra->numero_texto}.");
    }

    /* ---------------------------------------------------------- */

    protected function autorizarTipo(User $usuario, string $tipo): array
    {
        $config = self::TIPOS_MANUALES[$tipo] ?? abort(404);

        abort_unless($usuario->tieneRol(...$config['roles']), 403, 'Tu rol no puede cargar este tipo de movimiento.');

        return $config;
    }

    protected function tiposPermitidos(User $usuario): array
    {
        return array_filter(self::TIPOS_MANUALES, fn ($config) => $usuario->tieneRol(...$config['roles']));
    }

    /**
     * Las líneas cargadas por presentación (ej. 2 "Pack x 6") se pasan a unidades del producto.
     * El costo cargado es por presentación, así que se divide por su contenido.
     */
    protected function convertirPresentaciones(array $lineas): array
    {
        $ids = collect($lineas)->pluck('presentacion_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $presentaciones = ProductoPresentacion::whereIn('id', $ids)->get()->keyBy('id');

        return collect($lineas)->values()->map(function ($linea, $i) use ($presentaciones) {
            $presentacion = ! empty($linea['presentacion_id']) ? $presentaciones->get((int) $linea['presentacion_id']) : null;

            if (! empty($linea['presentacion_id']) && (! $presentacion || $presentacion->producto_id !== (int) $linea['producto_id'])) {
                throw ValidationException::withMessages(["lineas.{$i}.presentacion_id" => 'La presentación no corresponde al producto.']);
            }

            if (! $presentacion) {
                $linea['presentacion_id'] = null;

                return $linea;
            }

            $factor = (float) $presentacion->cantidad;
            $linea['cantidad_presentacion'] = (float) $linea['cantidad'];
            $linea['cantidad'] = (float) $linea['cantidad'] * $factor;

            if (isset($linea['costo_unitario']) && $linea['costo_unitario'] !== '' && $factor > 0) {
                $linea['costo_unitario'] = (float) $linea['costo_unitario'] / $factor;
            }

            return $linea;
        })->all();
    }

    /** Ubicaciones activas de cada depósito, para los selectores del formulario. */
    protected function ubicacionesPorDeposito($depositos): array
    {
        $ids = $depositos->where('usa_ubicaciones', true)->pluck('id');

        return Ubicacion::activas()
            ->whereIn('deposito_id', $ids)
            ->orderBy('codigo')
            ->get(['id', 'deposito_id', 'codigo', 'nombre'])
            ->groupBy('deposito_id')
            ->map(fn ($grupo) => $grupo->map(fn ($u) => ['id' => $u->id, 'etiqueta' => $u->etiqueta])->values())
            ->all();
    }

    /** Para volver a dibujar las líneas si la validación falla (el stock se consulta desde la pantalla). */
    protected function lineasPrevias(array $lineas): array
    {
        if (! $lineas) {
            return [];
        }

        $productos = Producto::with(['unidad', 'presentaciones'])
            ->whereIn('id', collect($lineas)->pluck('producto_id')->filter())
            ->get()
            ->keyBy('id');

        $lotesConocidos = $this->lotesConocidos($productos->where('usa_lotes', true)->keys()->all());

        return collect($lineas)->map(function ($linea) use ($productos, $lotesConocidos) {
            $producto = $productos->get((int) ($linea['producto_id'] ?? 0));

            if (! $producto) {
                return null;
            }

            $presentacion = ! empty($linea['presentacion_id'])
                ? $producto->presentaciones->firstWhere('id', (int) $linea['presentacion_id'])
                : null;

            return [
                'producto' => [
                    'id' => $producto->id,
                    'sku' => $producto->sku,
                    'nombre' => $producto->nombre,
                    'unidad' => $producto->unidad?->abreviatura,
                    'permite_decimales' => (bool) $producto->unidad?->permite_decimales,
                    'costo' => (float) $producto->costo_valuacion,
                    'usa_lotes' => $producto->usa_lotes,
                    'usa_series' => $producto->usa_series,
                    'lotes_conocidos' => $lotesConocidos[$producto->id] ?? [],
                    'presentacion' => $presentacion ? [
                        'id' => $presentacion->id,
                        'nombre' => $presentacion->nombre,
                        'cantidad' => (float) $presentacion->cantidad,
                    ] : null,
                ],
                'cantidad' => $linea['cantidad'] ?? '',
                'costo_unitario' => $linea['costo_unitario'] ?? '',
                'ubicacion_id' => $linea['ubicacion_id'] ?? '',
                'lote_id' => $linea['lote_id'] ?? '',
                'lote_codigo' => $linea['lote_codigo'] ?? '',
                'lote_vencimiento' => $linea['lote_vencimiento'] ?? '',
                'series' => $linea['series'] ?? '',
            ];
        })->filter()->values()->all();
    }

    /** Últimos lotes cargados de cada producto (para sugerirlos al escribir). */
    public static function lotesConocidos(array $productoIds): array
    {
        if (! $productoIds) {
            return [];
        }

        return \App\Models\Lote::whereIn('producto_id', $productoIds)
            ->orderByDesc('id')
            ->limit(300)
            ->get(['producto_id', 'codigo', 'fecha_vencimiento'])
            ->groupBy('producto_id')
            ->map(fn ($lotes) => $lotes->take(20)->map(fn ($l) => [
                'codigo' => $l->codigo,
                'vencimiento' => $l->fecha_vencimiento?->toDateString(),
            ])->values()->all())
            ->all();
    }

    protected function fecha(?string $valor): ?Carbon
    {
        if (blank($valor)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $valor);
        } catch (\Throwable) {
            return null;
        }
    }
}
