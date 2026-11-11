<?php

namespace App\Http\Requests;

use App\Models\Producto;
use App\Models\ProductoCodigo;
use App\Support\EmpresaActual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // los permisos los controla la ruta (rol:admin,encargado)
    }

    protected function prepareForValidation(): void
    {
        // Los importes pueden venir con coma decimal
        $normalizar = fn ($v) => is_string($v) ? str_replace(',', '.', trim($v)) : $v;

        $this->merge(collect(['costo', 'margen', 'precio_venta', 'stock_minimo', 'punto_reposicion', 'stock_maximo', 'peso_kg'])
            ->mapWithKeys(fn ($campo) => [$campo => $normalizar($this->input($campo))])
            ->all());
    }

    public function rules(): array
    {
        /** @var Producto|null $producto */
        $producto = $this->route('producto');
        $empresaId = EmpresaActual::id();
        $tipo = $producto?->tipo ?? $this->input('tipo');

        $reglas = [
            'sku' => [
                'nullable', 'string', 'max:50',
                Rule::unique('productos', 'sku')->where('empresa_id', $empresaId)->ignore($producto?->id),
            ],
            'costo' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'margen' => ['nullable', 'numeric', 'between:-100,100000'],
            'precio_venta' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'stock_minimo' => ['nullable', 'numeric', 'min:0'],
            'punto_reposicion' => ['nullable', 'numeric', 'min:0'],
            'stock_maximo' => ['nullable', 'numeric', 'min:0'],
            'peso_kg' => ['nullable', 'numeric', 'min:0'],

            'codigos' => ['nullable', 'array', 'max:20'],
            'codigos.*.codigo' => ['required', 'string', 'max:64'],
            'codigos.*.tipo' => ['required', Rule::in(array_keys(ProductoCodigo::TIPOS))],

            'presentaciones' => ['nullable', 'array', 'max:20'],
            'presentaciones.*.id' => ['nullable', 'integer'],
            'presentaciones.*.nombre' => ['required', 'string', 'max:60'],
            'presentaciones.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'presentaciones.*.precio_venta' => ['nullable', 'numeric', 'min:0'],
            'presentaciones.*.codigo_barras' => ['nullable', 'string', 'max:64'],

            'imagenes' => ['nullable', 'array', 'max:10'],
            'imagenes.*' => ['image', 'max:4096'],
            'eliminar_imagenes' => ['nullable', 'array'],
            'eliminar_imagenes.*' => ['integer'],
        ];

        if ($tipo !== Producto::TIPO_VARIANTE) {
            $reglas += [
                'nombre' => ['required', 'string', 'max:200'],
                'descripcion' => ['nullable', 'string', 'max:5000'],
                'categoria_id' => [
                    'nullable', 'integer',
                    Rule::exists('categorias', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at'),
                ],
                'marca_id' => [
                    'nullable', 'integer',
                    Rule::exists('marcas', 'id')->where('empresa_id', $empresaId)->whereNull('deleted_at'),
                ],
                'unidad_medida_id' => [
                    'required', 'integer',
                    Rule::exists('unidades_medida', 'id')->where('empresa_id', $empresaId),
                ],
                'alicuota_iva_id' => [
                    'required', 'integer',
                    Rule::exists('alicuotas_iva', 'id')->where('activa', true),
                ],
            ];
        }

        if (! $producto) {
            $reglas['tipo'] = ['required', Rule::in([Producto::TIPO_SIMPLE, Producto::TIPO_CON_VARIANTES, Producto::TIPO_SERVICIO])];
        }

        if ($tipo === Producto::TIPO_CON_VARIANTES) {
            $reglas += [
                'variantes_atributos' => ['required', 'array', 'min:1', 'max:3'],
                'variantes_atributos.*.nombre' => ['nullable', 'string', 'max:30', 'distinct'],
                'variantes_atributos.*.valores' => ['nullable', 'string', 'max:500'],
                'variantes' => ['nullable', 'array'],
                'variantes.*.sku' => ['required', 'string', 'max:50'],
                'variantes.*.costo' => ['required', 'numeric', 'min:0'],
                'variantes.*.precio_venta' => ['required', 'numeric', 'min:0'],
                'variantes.*.stock_minimo' => ['nullable', 'numeric', 'min:0'],
            ];
        }

        return $reglas;
    }

    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'codigos.*.codigo' => 'código de barras',
            'presentaciones.*.nombre' => 'nombre de la presentación',
            'presentaciones.*.cantidad' => 'cantidad de la presentación',
            'presentaciones.*.precio_venta' => 'precio de la presentación',
            'variantes_atributos.*.nombre' => 'nombre del atributo',
            'variantes.*.sku' => 'SKU de la variante',
            'unidad_medida_id' => 'unidad de medida',
            'alicuota_iva_id' => 'alícuota de IVA',
            'imagenes.*' => 'imagen',
        ];
    }

    /** Datos validados + interruptores (checkbox) resueltos. */
    public function datos(): array
    {
        $datos = $this->validated();

        foreach (['controla_stock', 'usa_lotes', 'usa_series', 'activo', 'aplicar_precios_variantes'] as $campo) {
            $datos[$campo] = $this->boolean($campo);
        }

        foreach ($datos['variantes'] ?? [] as $id => $fila) {
            $datos['variantes'][$id]['activo'] = (bool) $this->input("variantes.{$id}.activo");
        }

        unset($datos['imagenes']);

        return $datos;
    }
}
