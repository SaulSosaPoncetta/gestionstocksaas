<?php

namespace App\Http\Controllers\Compras;

use App\Http\Controllers\Admin\EmpresaAdminController;
use App\Http\Controllers\Configuracion\EmpresaController;
use App\Http\Controllers\Controller;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Support\EmpresaActual;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProveedorController extends Controller
{
    public function index(Request $request)
    {
        $buscar = trim((string) $request->query('q'));
        $estado = $request->query('estado', 'activos');

        $proveedores = Proveedor::query()
            ->withCount(['productos', 'ordenes as ordenes_abiertas_count' => fn ($q) => $q->whereIn('estado', OrdenCompra::ABIERTAS)])
            ->buscar($buscar)
            ->when($estado === 'activos', fn ($q) => $q->where('activo', true))
            ->when($estado === 'inactivos', fn ($q) => $q->where('activo', false))
            ->orderBy('razon_social')
            ->paginate(30)
            ->withQueryString();

        return view('compras.proveedores', [
            'proveedores' => $proveedores,
            'buscar' => $buscar,
            'estado' => $estado,
            'maestroExterno' => Proveedor::maestroExterno(),
        ]);
    }

    public function create()
    {
        $this->verificarMaestro();

        return view('compras.proveedor-form', [
            'proveedor' => new Proveedor(['activo' => true, 'condicion_iva' => 'responsable_inscripto']),
            'condiciones' => EmpresaController::CONDICIONES_IVA,
        ]);
    }

    public function store(Request $request)
    {
        $this->verificarMaestro();

        $proveedor = Proveedor::create($this->validar($request));

        return redirect()->route('compras.proveedores.show', $proveedor)->with('success', 'Proveedor creado. Ya podés asociarle productos.');
    }

    public function show(Proveedor $proveedor)
    {
        $proveedor->load(['productos.producto.unidad', 'productos.presentacion']);

        return view('compras.proveedor', [
            'proveedor' => $proveedor,
            'ordenes' => $proveedor->ordenes()->with('deposito')->orderByDesc('id')->limit(10)->get(),
            'maestroExterno' => Proveedor::maestroExterno(),
        ]);
    }

    public function edit(Proveedor $proveedor)
    {
        $this->verificarMaestro();

        return view('compras.proveedor-form', [
            'proveedor' => $proveedor,
            'condiciones' => EmpresaController::CONDICIONES_IVA,
        ]);
    }

    public function update(Request $request, Proveedor $proveedor)
    {
        $this->verificarMaestro();

        $proveedor->update($this->validar($request, $proveedor));

        return redirect()->route('compras.proveedores.show', $proveedor)->with('success', 'Proveedor actualizado.');
    }

    public function destroy(Proveedor $proveedor)
    {
        $this->verificarMaestro();

        if ($proveedor->ordenes()->exists()) {
            return back()->with('error', 'No se puede eliminar: tiene órdenes de compra. Podés desactivarlo.');
        }

        $proveedor->productos()->delete();
        $proveedor->delete();

        return redirect()->route('compras.proveedores.index')->with('success', 'Proveedor eliminado.');
    }

    /* ---------- Productos que vende el proveedor ---------- */

    public function guardarProducto(Request $request, Proveedor $proveedor)
    {
        $datos = $request->validate([
            'producto_id' => ['required', 'integer', Rule::exists('productos', 'id')->where('empresa_id', EmpresaActual::id())->whereNull('deleted_at')],
            'codigo_proveedor' => ['nullable', 'string', 'max:60'],
            'presentacion_id' => ['nullable', 'integer', Rule::exists('producto_presentaciones', 'id')->where('producto_id', $request->integer('producto_id'))],
            'costo' => ['nullable', 'numeric', 'min:0'],
            'minimo_compra' => ['nullable', 'numeric', 'min:0'],
            'plazo_entrega_dias' => ['nullable', 'integer', 'between:0,365'],
        ]);

        $producto = Producto::findOrFail($datos['producto_id']);

        if (! $producto->manejaStock()) {
            return back()->with('error', "{$producto->nombre} no controla stock: no se compra desde Abastecimiento.");
        }

        $vinculo = ProductoProveedor::updateOrCreate(
            ['producto_id' => $producto->id, 'proveedor_id' => $proveedor->id],
            [
                'codigo_proveedor' => $datos['codigo_proveedor'] ?? null,
                'presentacion_id' => $datos['presentacion_id'] ?? null,
                'costo' => $datos['costo'] ?? null,
                'minimo_compra' => $datos['minimo_compra'] ?? null,
                'plazo_entrega_dias' => $datos['plazo_entrega_dias'] ?? null,
            ]
        );

        if ($request->boolean('es_principal') || ! ProductoProveedor::where('producto_id', $producto->id)->where('es_principal', true)->exists()) {
            $vinculo->marcarPrincipal();
        }

        return back()->with('success', "{$producto->nombre} asociado al proveedor.");
    }

    public function quitarProducto(Proveedor $proveedor, ProductoProveedor $vinculo)
    {
        abort_unless($vinculo->proveedor_id === $proveedor->id, 404);

        $vinculo->delete();

        return back()->with('success', 'Producto desvinculado del proveedor.');
    }

    /* ---------------------------------------------------------- */

    protected function validar(Request $request, ?Proveedor $proveedor = null): array
    {
        if (filled($request->input('cuit'))) {
            $request->merge(['cuit' => \App\Models\Empresa::normalizarCuit((string) $request->input('cuit'))]);
        }

        $datos = $request->validate([
            'razon_social' => ['required', 'string', 'max:255'],
            'nombre_fantasia' => ['nullable', 'string', 'max:255'],
            'cuit' => [
                'nullable', 'regex:/^\d{2}-\d{8}-\d$/',
                Rule::unique('proveedores', 'cuit')->where('empresa_id', EmpresaActual::id())->whereNull('deleted_at')->ignore($proveedor?->id),
                function ($atributo, $valor, $fallar) {
                    if ($valor && ! EmpresaAdminController::cuitValido($valor)) {
                        $fallar('El CUIT no es válido (dígito verificador incorrecto).');
                    }
                },
            ],
            'condicion_iva' => ['required', Rule::in(array_keys(EmpresaController::CONDICIONES_IVA))],
            'contacto' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefono' => ['nullable', 'string', 'max:50'],
            'domicilio' => ['nullable', 'string', 'max:255'],
            'localidad' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'max:100'],
            'condiciones_pago' => ['nullable', 'string', 'max:150'],
            'plazo_entrega_dias' => ['nullable', 'integer', 'between:0,365'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ], [
            'cuit.regex' => 'El CUIT tiene que tener 11 dígitos.',
            'cuit.unique' => 'Ya hay un proveedor con ese CUIT.',
        ]);

        $datos['activo'] = $request->boolean('activo');

        return $datos;
    }

    /** Si otro sistema es dueño de los proveedores, acá no se crean ni editan. */
    protected function verificarMaestro(): void
    {
        if ($sistema = Proveedor::maestroExterno()) {
            abort(redirect()->route('compras.proveedores.index')
                ->with('warning', "Los proveedores se administran en {$sistema} y se sincronizan solos."));
        }
    }
}
