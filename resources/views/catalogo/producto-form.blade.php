{{-- resources/views/catalogo/producto-form.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', $producto->exists ? $producto->nombre : 'Nuevo producto')

@php
    $esNuevo = ! $producto->exists;
    $esVariante = $producto->esVariante();
    $esAgrupador = $producto->esAgrupador();
    $esServicio = $producto->esServicio();
    $num = fn ($v) => $v === null ? '' : (float) $v;

    $codigos = old('codigos', $producto->codigosPropios
        ->map(fn ($c) => ['codigo' => $c->codigo, 'tipo' => $c->tipo])->values()->all());

    $presentaciones = old('presentaciones', $producto->presentaciones
        ->map(fn ($p) => [
            'id' => $p->id,
            'nombre' => $p->nombre,
            'cantidad' => (float) $p->cantidad,
            'precio_venta' => $p->precio_venta === null ? '' : (float) $p->precio_venta,
            'codigo_barras' => $p->codigo?->codigo,
        ])->values()->all());

    $atributosVariantes = old('variantes_atributos', collect($producto->opciones_variantes ?? [])
        ->map(fn ($valores, $nombre) => ['nombre' => $nombre, 'valores' => implode(', ', $valores)])
        ->values()->all());

    if ($esAgrupador && ! $atributosVariantes) {
        $atributosVariantes = [['nombre' => 'Talle', 'valores' => ''], ['nombre' => 'Color', 'valores' => '']];
    }

    $titulos = [
        'simple' => 'Nuevo producto',
        'con_variantes' => 'Nuevo producto con variantes',
        'servicio' => 'Nuevo servicio',
    ];
@endphp

@push('styles')
<style>
    .foto { width: 110px; height: 110px; object-fit: cover; border-radius: .6rem; border: 1px solid #e2e8f0; }
    .foto-caja { position: relative; display: inline-block; }
    .foto-caja .form-check { position: absolute; top: 4px; left: 6px; background: rgba(255,255,255,.9); border-radius: .4rem; padding: 0 .4rem 0 1.8rem; }
    .nav-tabs .nav-link { color: #475569; }
    .nav-tabs .nav-link.active { font-weight: 600; }
    .tabla-variantes input { min-width: 90px; }
</style>
@endpush

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ $esVariante ? route('catalogo.productos.edit', $producto->padre_id) : route('catalogo.productos.index') }}" class="btn btn-light">
            <i class="bi bi-arrow-left"></i>
        </a>
        <div class="me-auto">
            <h1 class="h4 mb-0">{{ $esNuevo ? $titulos[$producto->tipo] : $producto->nombre }}</h1>
            @unless ($esNuevo)
                <span class="text-muted small"><code>{{ $producto->sku }}</code> · {{ $producto->tipo_nombre }}</span>
            @endunless
        </div>
        @unless ($esNuevo)
            <form method="POST" action="{{ route('catalogo.productos.destroy', $producto) }}"
                  onsubmit="return confirm('{{ $esAgrupador ? '¿Eliminar el producto y todas sus variantes?' : '¿Eliminar este producto?' }}');">
                @csrf
                @method('DELETE')
                <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Eliminar</button>
            </form>
        @endunless
    </div>

    @if ($esVariante)
        <div class="alert alert-light border d-flex align-items-center gap-2">
            <i class="bi bi-diagram-2 fs-5"></i>
            <div>
                Variante de <a href="{{ route('catalogo.productos.edit', $producto->padre_id) }}" class="fw-semibold">{{ $producto->padre?->nombre }}</a>.
                @foreach ($producto->atributos ?? [] as $atributo => $valor)
                    <span class="badge text-bg-secondary">{{ $atributo }}: {{ $valor }}</span>
                @endforeach
                <div class="small text-muted">Categoría, marca, unidad, IVA y descripción se editan en el producto principal.</div>
            </div>
        </div>
    @endif

    <form method="POST" enctype="multipart/form-data"
          action="{{ $esNuevo ? route('catalogo.productos.store') : route('catalogo.productos.update', $producto) }}">
        @csrf
        @unless ($esNuevo)
            @method('PUT')
        @endunless
        @if ($esNuevo)
            <input type="hidden" name="tipo" value="{{ $producto->tipo }}">
        @endif

        <ul class="nav nav-tabs mb-0" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-general" type="button">General</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-precios" type="button">Precios</button></li>
            @unless ($esServicio)
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-stock" type="button">Stock</button></li>
            @endunless
            @if ($esAgrupador)
                <li class="nav-item">
                    <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-variantes" type="button">
                        Variantes @if ($producto->variantes->count())<span class="badge text-bg-secondary">{{ $producto->variantes->count() }}</span>@endif
                    </button>
                </li>
            @else
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-codigos" type="button">Códigos de barras</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-presentaciones" type="button">Presentaciones</button></li>
            @endif
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-imagenes" type="button">Imágenes</button></li>
        </ul>

        <div class="card rounded-top-0">
            <div class="card-body tab-content">

                {{-- ================= GENERAL ================= --}}
                <div class="tab-pane fade show active" id="tab-general">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Nombre *</label>
                            <input type="text" name="nombre" class="form-control" maxlength="200"
                                   value="{{ old('nombre', $producto->nombre) }}" @disabled($esVariante) @required(! $esVariante)>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">SKU / código interno</label>
                            <input type="text" name="sku" class="form-control" maxlength="50" value="{{ old('sku', $producto->sku) }}"
                                   placeholder="{{ $esNuevo ? 'Se genera solo' : '' }}">
                        </div>

                        @unless ($esVariante)
                            <div class="col-md-4">
                                <label class="form-label">Categoría</label>
                                <select name="categoria_id" class="form-select">
                                    <option value="">Sin categoría</option>
                                    @foreach ($categorias as $c)
                                        <option value="{{ $c->id }}" @selected((string) old('categoria_id', $producto->categoria_id) === (string) $c->id)>
                                            {{ str_repeat('— ', $c->nivel) }}{{ $c->nombre }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Marca</label>
                                <select name="marca_id" class="form-select">
                                    <option value="">Sin marca</option>
                                    @foreach ($marcas as $m)
                                        <option value="{{ $m->id }}" @selected((string) old('marca_id', $producto->marca_id) === (string) $m->id)>{{ $m->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Unidad de medida *</label>
                                <select name="unidad_medida_id" class="form-select" required>
                                    @foreach ($unidades as $u)
                                        <option value="{{ $u->id }}" @selected((string) old('unidad_medida_id', $producto->unidad_medida_id) === (string) $u->id)>
                                            {{ $u->nombre }} ({{ $u->abreviatura }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descripción</label>
                                <textarea name="descripcion" class="form-control" rows="3" maxlength="5000">{{ old('descripcion', $producto->descripcion) }}</textarea>
                            </div>
                        @endunless

                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="activo" id="activo" value="1"
                                       @checked($errors->any() ? old('activo') : $producto->activo)>
                                <label class="form-check-label" for="activo">Activo</label>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ================= PRECIOS ================= --}}
                <div class="tab-pane fade" id="tab-precios">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Alícuota de IVA *</label>
                            @if ($esVariante)
                                <input type="text" class="form-control" value="{{ $producto->alicuota?->nombre }}" disabled>
                                <input type="hidden" id="ivaPorcentaje" value="{{ $producto->porcentaje_iva }}">
                            @else
                                <select name="alicuota_iva_id" id="alicuota" class="form-select" required>
                                    @foreach ($alicuotas as $a)
                                        <option value="{{ $a->id }}" data-porcentaje="{{ (float) $a->porcentaje }}"
                                                @selected((string) old('alicuota_iva_id', $producto->alicuota_iva_id) === (string) $a->id)>{{ $a->nombre }}</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Costo (sin IVA) *</label>
                            <div class="input-group">
                                <span class="input-group-text">$</span>
                                <input type="number" name="costo" id="costo" class="form-control" step="any" min="0" required
                                       value="{{ old('costo', $num($producto->costo)) }}">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Margen</label>
                            <div class="input-group">
                                <input type="number" name="margen" id="margen" class="form-control" step="any"
                                       value="{{ old('margen', $num($producto->margen)) }}">
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Precio sin IVA *</label>
                            <input type="number" name="precio_venta" id="precioVenta" class="form-control" step="any" min="0" required
                                   value="{{ old('precio_venta', $num($producto->precio_venta)) }}">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Precio final</label>
                            <input type="number" id="precioFinal" class="form-control fw-semibold" step="any" min="0">
                            <div class="form-text">Con IVA</div>
                        </div>

                        @if (! $esNuevo && $producto->manejaStock())
                            <div class="col-12 small text-muted">
                                Costo promedio ponderado del stock: <strong>{{ Formato::moneda($producto->costo_promedio) }}</strong>
                                (lo calcula el sistema con cada ingreso; el costo de arriba es el de reposición).
                            </div>
                        @endif

                        @if ($esAgrupador && ! $esNuevo)
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="aplicar_precios_variantes" id="aplicarPrecios" value="1">
                                    <label class="form-check-label" for="aplicarPrecios">Aplicar este costo y precio a todas las variantes</label>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- ================= STOCK ================= --}}
                @unless ($esServicio)
                    <div class="tab-pane fade" id="tab-stock">
                        <div class="row g-3">
                            @unless ($esVariante)
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" name="controla_stock" id="controlaStock" value="1"
                                               @checked($errors->any() ? old('controla_stock') : $producto->controla_stock)>
                                        <label class="form-check-label" for="controlaStock">Controlar stock de este producto</label>
                                    </div>
                                    @if ($empresa->usa_lotes)
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" name="usa_lotes" id="usaLotes" value="1"
                                                   @checked($errors->any() ? old('usa_lotes') : $producto->usa_lotes)>
                                            <label class="form-check-label" for="usaLotes">Trabaja con lotes y fecha de vencimiento</label>
                                        </div>
                                    @endif
                                    @if ($empresa->usa_series)
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" name="usa_series" id="usaSeries" value="1"
                                                   @checked($errors->any() ? old('usa_series') : $producto->usa_series)>
                                            <label class="form-check-label" for="usaSeries">Trabaja con números de serie</label>
                                        </div>
                                    @endif
                                </div>
                            @endunless

                            @unless ($esAgrupador)
                                <div class="col-md-3">
                                    <label class="form-label">Stock mínimo</label>
                                    <input type="number" name="stock_minimo" class="form-control" step="any" min="0" value="{{ old('stock_minimo', $num($producto->stock_minimo)) }}">
                                    <div class="form-text">Debajo de esto se avisa.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Punto de reposición</label>
                                    <input type="number" name="punto_reposicion" class="form-control" step="any" min="0" value="{{ old('punto_reposicion', $num($producto->punto_reposicion)) }}">
                                    <div class="form-text">Al llegar acá se sugiere comprar.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Stock máximo</label>
                                    <input type="number" name="stock_maximo" class="form-control" step="any" min="0" value="{{ old('stock_maximo', $num($producto->stock_maximo)) }}">
                                    <div class="form-text">Hasta dónde reponer.</div>
                                </div>
                            @else
                                <div class="col-12 small text-muted">Los niveles de stock se cargan en cada variante.</div>
                            @endunless
                            <div class="col-md-3">
                                <label class="form-label">Peso</label>
                                <div class="input-group">
                                    <input type="number" name="peso_kg" class="form-control" step="any" min="0" value="{{ old('peso_kg', $num($producto->peso_kg)) }}">
                                    <span class="input-group-text">kg</span>
                                </div>
                            </div>
                            <div class="col-12">
                                <hr class="my-1">
                                @if ($esNuevo)
                                    <div class="alert alert-light border small mb-0">
                                        <i class="bi bi-info-circle"></i> Cuando guardes el producto vas a poder cargarle stock desde <strong>Movimientos</strong>.
                                    </div>
                                @elseif ($esAgrupador)
                                    <div class="alert alert-light border small mb-0">
                                        <i class="bi bi-info-circle"></i> El stock se lleva en cada variante (columna Stock de la solapa Variantes).
                                    </div>
                                @elseif (! $producto->manejaStock())
                                    <div class="alert alert-light border small mb-0">Este producto no controla stock.</div>
                                @else
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h2 class="h6 mb-0">Stock actual</h2>
                                        <a href="{{ route('inventario.kardex', $producto) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-journal-text"></i> Ver kardex</a>
                                    </div>
                                    @if ($existencias->isEmpty())
                                        <p class="text-muted small mb-0">Sin stock en tus depósitos.</p>
                                    @else
                                        <table class="table table-sm mb-0" style="max-width: 640px;">
                                            <thead>
                                            <tr><th>Depósito / ubicación</th><th class="text-end">Stock</th><th class="text-end">Reservado</th><th class="text-end">Disponible</th></tr>
                                            </thead>
                                            <tbody>
                                            @foreach ($existencias as $filas)
                                                <tr class="table-light fw-semibold">
                                                    <td>{{ $filas->first()->deposito?->nombre }}</td>
                                                    <td class="text-end">{{ Formato::cantidad($filas->sum('cantidad')) }}</td>
                                                    <td class="text-end">{{ Formato::cantidad($filas->sum('reservado')) }}</td>
                                                    <td class="text-end">{{ Formato::cantidad($filas->sum(fn ($e) => $e->disponible)) }}</td>
                                                </tr>
                                                @if ($filas->count() > 1 || $filas->first()->ubicacion_id)
                                                    @foreach ($filas as $e)
                                                        <tr class="small">
                                                            <td class="ps-4"><i class="bi bi-geo text-muted"></i> {{ $e->ubicacion?->codigo ?? 'Sin ubicar' }}</td>
                                                            <td class="text-end">{{ Formato::cantidad($e->cantidad) }}</td>
                                                            <td class="text-end">{{ Formato::cantidad($e->reservado) }}</td>
                                                            <td class="text-end">{{ Formato::cantidad($e->disponible) }}</td>
                                                        </tr>
                                                    @endforeach
                                                @endif
                                            @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @endunless

                {{-- ================= CÓDIGOS ================= --}}
                @unless ($esAgrupador)
                    <div class="tab-pane fade" id="tab-codigos">
                        <p class="text-muted small">Podés cargar varios códigos (el del fabricante, uno interno, el del proveedor…). Con el lector, hacé clic en el campo y escaneá.</p>
                        <table class="table align-middle" style="max-width: 640px;">
                            <thead><tr><th>Código</th><th style="width: 180px;">Tipo</th><th style="width: 50px;"></th></tr></thead>
                            <tbody id="filasCodigos">
                            @foreach ($codigos as $i => $c)
                                <tr>
                                    <td><input type="text" name="codigos[{{ $i }}][codigo]" class="form-control font-monospace" maxlength="64" value="{{ $c['codigo'] ?? '' }}" required></td>
                                    <td>
                                        <select name="codigos[{{ $i }}][tipo]" class="form-select">
                                            @foreach ($tiposCodigo as $valor => $texto)
                                                <option value="{{ $valor }}" @selected(($c['tipo'] ?? '') === $valor)>{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="agregarFila('filasCodigos', 'plantillaCodigo')">
                            <i class="bi bi-plus-lg"></i> Agregar código
                        </button>
                    </div>

                    {{-- ================= PRESENTACIONES ================= --}}
                    <div class="tab-pane fade" id="tab-presentaciones">
                        <p class="text-muted small">
                            Formas en que se compra o vende este producto además de la unidad: "Caja x 12", "Pack x 6", "Bolsa 25 kg".
                            La cantidad es en <strong>{{ $producto->unidad?->nombre ?? 'la unidad del producto' }}</strong>.
                        </p>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th style="width: 130px;">Cantidad</th>
                                    <th style="width: 160px;">Precio sin IVA</th>
                                    <th>Código de barras</th>
                                    <th style="width: 50px;"></th>
                                </tr>
                                </thead>
                                <tbody id="filasPresentaciones">
                                @foreach ($presentaciones as $i => $p)
                                    <tr>
                                        <td>
                                            @if (! empty($p['id']))
                                                <input type="hidden" name="presentaciones[{{ $i }}][id]" value="{{ $p['id'] }}">
                                            @endif
                                            <input type="text" name="presentaciones[{{ $i }}][nombre]" class="form-control" maxlength="60" value="{{ $p['nombre'] ?? '' }}" required>
                                        </td>
                                        <td><input type="number" name="presentaciones[{{ $i }}][cantidad]" class="form-control" step="any" min="0" value="{{ $p['cantidad'] ?? '' }}" required></td>
                                        <td><input type="number" name="presentaciones[{{ $i }}][precio_venta]" class="form-control" step="any" min="0" value="{{ $p['precio_venta'] ?? '' }}" placeholder="Opcional"></td>
                                        <td><input type="text" name="presentaciones[{{ $i }}][codigo_barras]" class="form-control font-monospace" maxlength="64" value="{{ $p['codigo_barras'] ?? '' }}"></td>
                                        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="agregarFila('filasPresentaciones', 'plantillaPresentacion')">
                            <i class="bi bi-plus-lg"></i> Agregar presentación
                        </button>
                    </div>
                @endunless

                {{-- ================= VARIANTES ================= --}}
                @if ($esAgrupador)
                    <div class="tab-pane fade" id="tab-variantes">
                        <h2 class="h6">Atributos</h2>
                        <p class="text-muted small mb-2">
                            Hasta 3 atributos. Separá los valores con comas. Al guardar se crean las combinaciones que falten;
                            si quitás un valor, sus variantes quedan inactivas (no se borran).
                        </p>
                        <div class="row g-2 mb-4" style="max-width: 760px;">
                            @for ($i = 0; $i < 3; $i++)
                                <div class="col-4">
                                    <input type="text" name="variantes_atributos[{{ $i }}][nombre]" class="form-control" maxlength="30"
                                           placeholder="Atributo (ej. Talle)" value="{{ $atributosVariantes[$i]['nombre'] ?? '' }}">
                                </div>
                                <div class="col-8">
                                    <input type="text" name="variantes_atributos[{{ $i }}][valores]" class="form-control" maxlength="500"
                                           placeholder="Valores (ej. S, M, L, XL)" value="{{ $atributosVariantes[$i]['valores'] ?? '' }}">
                                </div>
                            @endfor
                        </div>

                        @if ($producto->variantes->count())
                            <h2 class="h6">Variantes</h2>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle tabla-variantes">
                                    <thead class="table-light">
                                    <tr>
                                        <th>Variante</th>
                                        <th>SKU</th>
                                        <th>Costo</th>
                                        <th>Precio sin IVA</th>
                                        <th>Stock mín.</th>
                                        <th class="text-end">Stock</th>
                                        <th class="text-center">Activa</th>
                                        <th></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($producto->variantes as $v)
                                        <tr class="{{ $v->activo ? '' : 'table-secondary' }}">
                                            <td class="fw-semibold">{{ $v->atributos_texto }}</td>
                                            <td><input type="text" name="variantes[{{ $v->id }}][sku]" class="form-control form-control-sm" maxlength="50" value="{{ old("variantes.$v->id.sku", $v->sku) }}" required></td>
                                            <td><input type="number" name="variantes[{{ $v->id }}][costo]" class="form-control form-control-sm" step="any" min="0" value="{{ old("variantes.$v->id.costo", (float) $v->costo) }}" required></td>
                                            <td><input type="number" name="variantes[{{ $v->id }}][precio_venta]" class="form-control form-control-sm" step="any" min="0" value="{{ old("variantes.$v->id.precio_venta", (float) $v->precio_venta) }}" required></td>
                                            <td><input type="number" name="variantes[{{ $v->id }}][stock_minimo]" class="form-control form-control-sm" step="any" min="0" value="{{ old("variantes.$v->id.stock_minimo", (float) $v->stock_minimo) }}"></td>
                                            <td class="text-end fw-semibold">
                                                <a href="{{ route('inventario.kardex', $v) }}" class="text-decoration-none">{{ Formato::cantidad($v->stock_total ?? 0) }}</a>
                                            </td>
                                            <td class="text-center">
                                                <input type="hidden" name="variantes[{{ $v->id }}][activo]" value="0">
                                                <input class="form-check-input" type="checkbox" name="variantes[{{ $v->id }}][activo]" value="1" @checked(old("variantes.$v->id.activo", $v->activo))>
                                            </td>
                                            <td class="text-end">
                                                <a href="{{ route('catalogo.productos.edit', $v) }}" class="btn btn-sm btn-outline-secondary" title="Códigos, presentaciones e imágenes de esta variante">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif

                {{-- ================= IMÁGENES ================= --}}
                <div class="tab-pane fade" id="tab-imagenes">
                    @if ($producto->imagenes->count())
                        <div class="d-flex flex-wrap gap-3 mb-3">
                            @foreach ($producto->imagenes as $img)
                                <div class="foto-caja">
                                    <img src="{{ $img->url }}" alt="" class="foto">
                                    <div class="form-check small">
                                        <input class="form-check-input" type="checkbox" name="eliminar_imagenes[]" value="{{ $img->id }}" id="img{{ $img->id }}">
                                        <label class="form-check-label" for="img{{ $img->id }}">Quitar</label>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <div class="small text-muted mb-3">La primera es la imagen principal.</div>
                    @endif
                    <label class="form-label">Agregar imágenes</label>
                    <input type="file" name="imagenes[]" class="form-control" accept="image/*" multiple style="max-width: 480px;">
                    <div class="form-text">Hasta 10 por vez, 4 MB cada una.</div>
                </div>
            </div>

            <div class="card-footer bg-white">
                <button class="btn btn-stk"><i class="bi bi-check-lg"></i> {{ $esNuevo ? 'Crear' : 'Guardar cambios' }}</button>
                <a href="{{ route('catalogo.productos.index') }}" class="btn btn-light">Cancelar</a>
            </div>
        </div>
    </form>

    {{-- Plantillas de filas --}}
    <template id="plantillaCodigo">
        <tr>
            <td><input type="text" name="codigos[__i__][codigo]" class="form-control font-monospace" maxlength="64" required></td>
            <td>
                <select name="codigos[__i__][tipo]" class="form-select">
                    @foreach ($tiposCodigo as $valor => $texto)
                        <option value="{{ $valor }}">{{ $texto }}</option>
                    @endforeach
                </select>
            </td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td>
        </tr>
    </template>

    <template id="plantillaPresentacion">
        <tr>
            <td><input type="text" name="presentaciones[__i__][nombre]" class="form-control" maxlength="60" placeholder="Caja x 12" required></td>
            <td><input type="number" name="presentaciones[__i__][cantidad]" class="form-control" step="any" min="0" placeholder="12" required></td>
            <td><input type="number" name="presentaciones[__i__][precio_venta]" class="form-control" step="any" min="0" placeholder="Opcional"></td>
            <td><input type="text" name="presentaciones[__i__][codigo_barras]" class="form-control font-monospace" maxlength="64"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()"><i class="bi bi-x-lg"></i></button></td>
        </tr>
    </template>
@endsection

@push('scripts')
<script>
    /* ---------- Filas dinámicas ---------- */
    let contadorFilas = 1000;

    function agregarFila(tbodyId, plantillaId) {
        const html = document.getElementById(plantillaId).innerHTML.replaceAll('__i__', contadorFilas++);
        const tbody = document.getElementById(tbodyId);
        tbody.insertAdjacentHTML('beforeend', html);

        const campo = tbody.lastElementChild.querySelector('input');
        campo?.focus();
    }

    // Evitar que el Enter del lector de barras envíe el formulario
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('#filasCodigos input, #filasPresentaciones input')) {
            e.preventDefault();
        }
    });

    /* ---------- Cálculo de precios ---------- */
    const campoCosto = document.getElementById('costo');
    const campoMargen = document.getElementById('margen');
    const campoPrecio = document.getElementById('precioVenta');
    const campoFinal = document.getElementById('precioFinal');
    const selectIva = document.getElementById('alicuota');
    const ivaFijo = document.getElementById('ivaPorcentaje');

    const leer = (campo) => parseFloat(campo.value) || 0;
    const redondear = (valor, decimales = 2) => Math.round(valor * 10 ** decimales) / 10 ** decimales;

    function porcentajeIva() {
        if (selectIva) {
            return parseFloat(selectIva.options[selectIva.selectedIndex].dataset.porcentaje) || 0;
        }
        return parseFloat(ivaFijo?.value) || 0;
    }

    function actualizarFinal() {
        campoFinal.value = redondear(leer(campoPrecio) * (1 + porcentajeIva() / 100));
    }

    function actualizarMargen() {
        const costo = leer(campoCosto);
        campoMargen.value = costo > 0 ? redondear((leer(campoPrecio) / costo - 1) * 100) : '';
    }

    function desdeCostoYMargen() {
        if (campoMargen.value === '') {
            actualizarMargen();
            return;
        }
        campoPrecio.value = redondear(leer(campoCosto) * (1 + leer(campoMargen) / 100), 4);
        actualizarFinal();
    }

    campoCosto.addEventListener('input', desdeCostoYMargen);
    campoMargen.addEventListener('input', desdeCostoYMargen);
    campoPrecio.addEventListener('input', () => { actualizarMargen(); actualizarFinal(); });
    campoFinal.addEventListener('input', () => {
        campoPrecio.value = redondear(leer(campoFinal) / (1 + porcentajeIva() / 100), 4);
        actualizarMargen();
    });
    selectIva?.addEventListener('change', actualizarFinal);

    actualizarFinal();

    /* ---------- Abrir la solapa del primer error ---------- */
    @if ($errors->any())
        const primerError = @json(array_key_first($errors->toArray()));
        const solapa = primerError.startsWith('codigos') ? '#tab-codigos'
            : primerError.startsWith('presentaciones') ? '#tab-presentaciones'
            : primerError.startsWith('variantes') ? '#tab-variantes'
            : primerError.startsWith('imagenes') ? '#tab-imagenes'
            : ['costo', 'margen', 'precio_venta', 'alicuota_iva_id'].includes(primerError) ? '#tab-precios'
            : ['stock_minimo', 'punto_reposicion', 'stock_maximo', 'peso_kg'].includes(primerError) ? '#tab-stock'
            : null;
        if (solapa && document.querySelector(`[data-bs-target="${solapa}"]`)) {
            bootstrap.Tab.getOrCreateInstance(document.querySelector(`[data-bs-target="${solapa}"]`)).show();
        }
    @endif
</script>
@endpush
