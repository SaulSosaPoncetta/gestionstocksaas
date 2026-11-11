<?php

namespace App\Services;

use App\Models\Producto;
use App\Models\ProductoCodigo;
use App\Models\ProductoImagen;
use App\Models\ProductoPresentacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Alta y modificación de productos con todo lo que cuelga de ellos:
 * códigos de barras, presentaciones, imágenes y variantes.
 */
class ProductoService
{
    public const MAX_ATRIBUTOS = 3;
    public const MAX_VARIANTES = 200;

    protected const CAMPOS_FICHA = [
        'sku', 'nombre', 'descripcion', 'categoria_id', 'marca_id', 'unidad_medida_id', 'alicuota_iva_id',
        'controla_stock', 'usa_lotes', 'usa_series', 'costo', 'margen', 'precio_venta',
        'stock_minimo', 'punto_reposicion', 'stock_maximo', 'peso_kg', 'activo',
    ];

    /**
     * @param  UploadedFile[]  $imagenesNuevas
     */
    public function guardar(Producto $producto, array $datos, array $imagenesNuevas = []): Producto
    {
        return DB::transaction(function () use ($producto, $datos, $imagenesNuevas) {

            $campos = Arr::only($datos, self::CAMPOS_FICHA);

            // La variante no cambia lo que hereda ni su nombre (se arma con el del agrupador)
            if ($producto->esVariante()) {
                $campos = Arr::except($campos, array_merge(Producto::CAMPOS_HEREDADOS, ['nombre']));
            }

            $producto->fill($campos);

            foreach (['stock_minimo'] as $campo) {
                $producto->{$campo} ??= 0;
            }

            if (blank($producto->sku)) {
                $producto->sku = $this->siguienteSku();
            }

            if ($producto->esServicio()) {
                $producto->controla_stock = false;
                $producto->usa_lotes = false;
                $producto->usa_series = false;
            }

            if ($producto->esAgrupador()) {
                $producto->opciones_variantes = $this->normalizarOpciones($datos['variantes_atributos'] ?? []);
            }

            // Verificar todos los códigos de barras antes de guardar nada
            $this->verificarCodigos($producto, array_merge(
                Arr::pluck($datos['codigos'] ?? [], 'codigo'),
                Arr::pluck($datos['presentaciones'] ?? [], 'codigo_barras')
            ));

            $producto->save();

            $this->sincronizarCodigos($producto, $datos['codigos'] ?? []);
            $this->sincronizarPresentaciones($producto, $datos['presentaciones'] ?? []);
            $this->eliminarImagenes($producto, $datos['eliminar_imagenes'] ?? []);
            $this->agregarImagenes($producto, $imagenesNuevas);

            if ($producto->esAgrupador()) {
                $this->actualizarVariantes($producto, $datos['variantes'] ?? []);
                $this->propagarAVariantes($producto, (bool) ($datos['aplicar_precios_variantes'] ?? false));
                $this->generarVariantes($producto);
            }

            return $producto->fresh();
        });
    }

    public function eliminar(Producto $producto): void
    {
        DB::transaction(function () use ($producto) {
            $productos = collect([$producto])->merge($producto->variantes()->get());

            foreach ($productos as $p) {
                // Liberar los códigos para que se puedan volver a usar
                ProductoCodigo::where('producto_id', $p->id)->delete();
                $p->delete();
            }
        });
    }

    /* =========================================================
       Búsqueda por código (lector de barras)
       ========================================================= */

    /**
     * Busca por SKU o código de barras exacto.
     * Devuelve ['producto' => Producto, 'presentacion' => ?ProductoPresentacion] o null.
     */
    public function buscarPorCodigo(string $codigo): ?array
    {
        $codigo = trim($codigo);

        if ($codigo === '') {
            return null;
        }

        $registro = ProductoCodigo::with(['producto', 'presentacion'])->where('codigo', $codigo)->first();

        if ($registro && $registro->producto && ! $registro->producto->trashed()) {
            return ['producto' => $registro->producto, 'presentacion' => $registro->presentacion];
        }

        $producto = Producto::where('sku', $codigo)->first();

        return $producto ? ['producto' => $producto, 'presentacion' => null] : null;
    }

    /* =========================================================
       SKU
       ========================================================= */

    public function siguienteSku(string $prefijo = 'P'): string
    {
        $numero = Producto::withTrashed()->count() + 1;

        do {
            $sku = $prefijo . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
            $numero++;
        } while (Producto::withTrashed()->where('sku', $sku)->exists());

        return $sku;
    }

    protected function skuDisponible(string $base, ?int $ignorarId = null): string
    {
        $base = Str::limit($base, 46, '');
        $sku = $base;
        $i = 2;

        while (Producto::withTrashed()->where('sku', $sku)->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))->exists()) {
            $sku = "{$base}-{$i}";
            $i++;
        }

        return $sku;
    }

    /* =========================================================
       Códigos de barras
       ========================================================= */

    protected function verificarCodigos(Producto $producto, array $codigos): void
    {
        $codigos = array_values(array_filter(array_map(fn ($c) => trim((string) $c), $codigos)));

        if (! $codigos) {
            return;
        }

        if (count($codigos) !== count(array_unique($codigos))) {
            throw ValidationException::withMessages(['codigos' => 'Hay códigos de barras repetidos en el formulario.']);
        }

        $propios = $producto->exists
            ? array_merge([$producto->id], $producto->variantes()->pluck('id')->all())
            : [0];

        $usado = ProductoCodigo::with('producto')
            ->whereIn('codigo', $codigos)
            ->whereNotIn('producto_id', $propios)
            ->first();

        if ($usado) {
            $nombre = $usado->producto?->nombre ?? 'otro producto';
            throw ValidationException::withMessages([
                'codigos' => "El código {$usado->codigo} ya está asignado a {$nombre}.",
            ]);
        }
    }

    protected function sincronizarCodigos(Producto $producto, array $filas): void
    {
        ProductoCodigo::where('producto_id', $producto->id)->whereNull('presentacion_id')->delete();

        foreach ($filas as $fila) {
            $codigo = trim((string) ($fila['codigo'] ?? ''));

            if ($codigo === '') {
                continue;
            }

            ProductoCodigo::create([
                'producto_id' => $producto->id,
                'codigo' => $codigo,
                'tipo' => $fila['tipo'] ?? ProductoCodigo::tipoSugerido($codigo),
            ]);
        }
    }

    /* =========================================================
       Presentaciones
       ========================================================= */

    protected function sincronizarPresentaciones(Producto $producto, array $filas): void
    {
        $existentes = $producto->presentaciones()->get()->keyBy('id');
        $conservar = [];

        foreach ($filas as $fila) {
            $id = isset($fila['id']) ? (int) $fila['id'] : null;

            $presentacion = $id && $existentes->has($id)
                ? $existentes->get($id)
                : new ProductoPresentacion(['producto_id' => $producto->id]);

            $presentacion->fill([
                'nombre' => $fila['nombre'],
                'cantidad' => $fila['cantidad'],
                'precio_venta' => ($fila['precio_venta'] ?? '') === '' ? null : $fila['precio_venta'],
                'activa' => true,
            ])->save();

            $conservar[] = $presentacion->id;

            // Código de barras propio de la presentación
            ProductoCodigo::where('presentacion_id', $presentacion->id)->delete();
            $codigo = trim((string) ($fila['codigo_barras'] ?? ''));

            if ($codigo !== '') {
                ProductoCodigo::create([
                    'producto_id' => $producto->id,
                    'presentacion_id' => $presentacion->id,
                    'codigo' => $codigo,
                    'tipo' => ProductoCodigo::tipoSugerido($codigo),
                ]);
            }
        }

        // Las que se quitaron del formulario (en pasos posteriores se controla que no tengan movimientos)
        foreach ($existentes->except($conservar) as $quitada) {
            ProductoCodigo::where('presentacion_id', $quitada->id)->delete();
            $quitada->delete();
        }
    }

    /* =========================================================
       Imágenes
       ========================================================= */

    protected function eliminarImagenes(Producto $producto, array $ids): void
    {
        if (! $ids) {
            return;
        }

        $imagenes = ProductoImagen::where('producto_id', $producto->id)->whereIn('id', $ids)->get();

        foreach ($imagenes as $imagen) {
            Storage::disk('public')->delete($imagen->ruta);
            $imagen->delete();
        }
    }

    /**
     * @param  UploadedFile[]  $archivos
     */
    protected function agregarImagenes(Producto $producto, array $archivos): void
    {
        $orden = (int) ProductoImagen::where('producto_id', $producto->id)->max('orden');

        foreach ($archivos as $archivo) {
            if (! $archivo instanceof UploadedFile) {
                continue;
            }

            $ruta = $archivo->store("productos/{$producto->empresa_id}/{$producto->id}", 'public');

            ProductoImagen::create([
                'producto_id' => $producto->id,
                'ruta' => $ruta,
                'orden' => ++$orden,
            ]);
        }
    }

    /* =========================================================
       Variantes
       ========================================================= */

    /**
     * Filas del formulario [{nombre: "Talle", valores: "S, M, L"}] →
     * ["Talle" => ["S","M","L"]]
     */
    protected function normalizarOpciones(array $filas): array
    {
        $opciones = [];

        foreach ($filas as $fila) {
            $nombre = trim((string) ($fila['nombre'] ?? ''));
            $valores = collect(explode(',', (string) ($fila['valores'] ?? '')))
                ->map(fn ($v) => trim($v))
                ->filter()
                ->unique(fn ($v) => Str::lower($v))
                ->values()
                ->all();

            if ($nombre !== '' && $valores) {
                $opciones[$nombre] = $valores;
            }
        }

        if (! $opciones) {
            throw ValidationException::withMessages([
                'variantes_atributos' => 'Cargá al menos un atributo con sus valores (por ejemplo Talle: S, M, L).',
            ]);
        }

        if (count($opciones) > self::MAX_ATRIBUTOS) {
            throw ValidationException::withMessages([
                'variantes_atributos' => 'Se permiten hasta ' . self::MAX_ATRIBUTOS . ' atributos.',
            ]);
        }

        $combinaciones = array_product(array_map('count', $opciones));

        if ($combinaciones > self::MAX_VARIANTES) {
            throw ValidationException::withMessages([
                'variantes_atributos' => "Esa combinación genera {$combinaciones} variantes; el máximo es " . self::MAX_VARIANTES . '.',
            ]);
        }

        return $opciones;
    }

    /** Ediciones rápidas de la tabla de variantes en la ficha del agrupador. */
    protected function actualizarVariantes(Producto $agrupador, array $filas): void
    {
        if (! $filas) {
            return;
        }

        $variantes = $agrupador->variantes()->get()->keyBy('id');

        foreach ($filas as $id => $fila) {
            $variante = $variantes->get((int) $id);

            if (! $variante) {
                continue;
            }

            $sku = trim((string) ($fila['sku'] ?? $variante->sku));

            if ($sku !== $variante->sku) {
                $existe = Producto::withTrashed()->where('sku', $sku)->whereKeyNot($variante->id)->exists();

                if ($existe) {
                    throw ValidationException::withMessages(["variantes.{$id}.sku" => "El SKU {$sku} ya está en uso."]);
                }
            }

            $variante->update([
                'sku' => $sku,
                'costo' => $fila['costo'] ?? $variante->costo,
                'precio_venta' => $fila['precio_venta'] ?? $variante->precio_venta,
                'stock_minimo' => ($fila['stock_minimo'] ?? '') === '' ? 0 : $fila['stock_minimo'],
                'activo' => (bool) ($fila['activo'] ?? false),
            ]);
        }
    }

    /** Copia a las variantes lo que heredan (y opcionalmente costo y precio). */
    protected function propagarAVariantes(Producto $agrupador, bool $conPrecios): void
    {
        $heredado = Arr::only($agrupador->getAttributes(), Producto::CAMPOS_HEREDADOS);

        if ($conPrecios) {
            $heredado['costo'] = $agrupador->costo;
            $heredado['margen'] = $agrupador->margen;
            $heredado['precio_venta'] = $agrupador->precio_venta;
        }

        foreach ($agrupador->variantes()->get() as $variante) {
            $variante->fill($heredado);
            $variante->nombre = $this->nombreVariante($agrupador, $variante->atributos ?? []);
            $variante->save();
        }
    }

    /**
     * Crea las combinaciones que faltan y desactiva las que ya no corresponden
     * (no se borran porque pueden tener stock o movimientos).
     */
    protected function generarVariantes(Producto $agrupador): void
    {
        $opciones = $agrupador->opciones_variantes ?? [];
        $combinaciones = $this->combinar($opciones);
        $claves = array_map(fn ($c) => $this->claveAtributos($c), $combinaciones);

        $existentes = $agrupador->variantes()->get()
            ->keyBy(fn ($v) => $this->claveAtributos($v->atributos ?? []));

        foreach ($combinaciones as $i => $atributos) {
            if ($existentes->has($claves[$i])) {
                continue;
            }

            $sufijo = collect($atributos)->map(fn ($v) => Str::upper(Str::slug($v, '')))->implode('-');

            Producto::create(array_merge(
                Arr::only($agrupador->getAttributes(), Producto::CAMPOS_HEREDADOS),
                [
                    'padre_id' => $agrupador->id,
                    'tipo' => Producto::TIPO_VARIANTE,
                    'sku' => $this->skuDisponible("{$agrupador->sku}-{$sufijo}"),
                    'nombre' => $this->nombreVariante($agrupador, $atributos),
                    'atributos' => $atributos,
                    'costo' => $agrupador->costo,
                    'margen' => $agrupador->margen,
                    'precio_venta' => $agrupador->precio_venta,
                    'stock_minimo' => 0,
                    'activo' => true,
                ]
            ));
        }

        foreach ($existentes as $clave => $variante) {
            if (! in_array($clave, $claves, true) && $variante->activo) {
                $variante->update(['activo' => false]);
            }
        }
    }

    /** Producto cartesiano: ["Talle"=>["S","M"],"Color"=>["Negro"]] → [[Talle=>S,Color=>Negro],[Talle=>M,Color=>Negro]] */
    protected function combinar(array $opciones): array
    {
        $resultado = [[]];

        foreach ($opciones as $atributo => $valores) {
            $nuevo = [];
            foreach ($resultado as $parcial) {
                foreach ($valores as $valor) {
                    $nuevo[] = $parcial + [$atributo => $valor];
                }
            }
            $resultado = $nuevo;
        }

        return $opciones ? $resultado : [];
    }

    protected function claveAtributos(array $atributos): string
    {
        $partes = [];
        ksort($atributos);

        foreach ($atributos as $k => $v) {
            $partes[] = Str::lower($k) . '=' . Str::lower((string) $v);
        }

        return implode('|', $partes);
    }

    protected function nombreVariante(Producto $agrupador, array $atributos): string
    {
        return Str::limit(trim($agrupador->nombre . ' ' . implode(' / ', array_values($atributos))), 200, '');
    }
}
