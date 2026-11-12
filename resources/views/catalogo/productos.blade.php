{{-- resources/views/catalogo/productos.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Productos')

@push('styles')
<style>
    .miniatura { width: 44px; height: 44px; object-fit: cover; border-radius: .5rem; background: #e2e8f0; }
    .miniatura-vacia { width: 44px; height: 44px; border-radius: .5rem; background: #e2e8f0; color: #94a3b8; display: grid; place-items: center; }
</style>
@endpush

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">Productos</h1>
        @if ($puedeEditar)
            <div class="dropdown">
                <button class="btn btn-stk dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-plus-lg"></i> Nuevo producto
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="{{ route('catalogo.productos.create', ['tipo' => 'simple']) }}"><i class="bi bi-box"></i> Producto simple</a></li>
                    <li><a class="dropdown-item" href="{{ route('catalogo.productos.create', ['tipo' => 'con_variantes']) }}"><i class="bi bi-grid-3x3-gap"></i> Producto con variantes (talle, color…)</a></li>
                    <li><a class="dropdown-item" href="{{ route('catalogo.productos.create', ['tipo' => 'servicio']) }}"><i class="bi bi-tools"></i> Servicio (sin stock)</a></li>
                </ul>
            </div>
        @endif
    </div>

    {{-- Filtros --}}
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label small mb-1">Buscar</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                    <input type="search" name="q" class="form-control" placeholder="Nombre, SKU o código de barras" value="{{ $filtros['q'] }}" autofocus>
                </div>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Categoría</label>
                <select name="categoria_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach ($categorias as $c)
                        <option value="{{ $c->id }}" @selected($filtros['categoria_id'] === $c->id)>{{ str_repeat('— ', $c->nivel) }}{{ $c->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Marca</label>
                <select name="marca_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach ($marcas as $m)
                        <option value="{{ $m->id }}" @selected($filtros['marca_id'] === $m->id)>{{ $m->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Tipo</label>
                <select name="tipo" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($tipos as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['tipo'] === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-1">
                <label class="form-label small mb-1">Estado</label>
                <select name="estado" class="form-select">
                    <option value="activos" @selected($filtros['estado'] === 'activos')>Activos</option>
                    <option value="inactivos" @selected($filtros['estado'] === 'inactivos')>Inactivos</option>
                    <option value="todos" @selected($filtros['estado'] === 'todos')>Todos</option>
                </select>
            </div>
            <div class="col-lg-1 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th style="width:60px;"></th>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th>Marca</th>
                    <th class="text-end">Costo</th>
                    <th class="text-end">Precio final</th>
                    <th class="text-end">Stock</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($productos as $p)
                    <tr class="{{ $p->activo ? '' : 'text-muted' }}">
                        <td>
                            @if ($p->imagen_url)
                                <img src="{{ $p->imagen_url }}" alt="" class="miniatura">
                            @else
                                <div class="miniatura-vacia"><i class="bi bi-image"></i></div>
                            @endif
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $p->nombre }}</div>
                            <div class="small text-muted">
                                <code>{{ $p->sku }}</code>
                                @if ($p->tipo !== 'simple')
                                    · <span class="badge text-bg-light border">{{ $p->tipo_nombre }}</span>
                                @endif
                                @if ($p->variantes_count)
                                    · {{ $p->variantes_count }} variantes
                                @endif
                                @unless ($p->activo)
                                    · <span class="badge text-bg-secondary">Inactivo</span>
                                @endunless
                            </div>
                        </td>
                        <td class="small">{{ $p->categoria?->nombre }}</td>
                        <td class="small">{{ $p->marca?->nombre }}</td>
                        <td class="text-end">$ {{ number_format((float) $p->costo, 2, ',', '.') }}</td>
                        <td class="text-end fw-semibold">$ {{ number_format($p->precio_final, 2, ',', '.') }}</td>
                        <td class="text-end text-nowrap">
                            @if ($p->esAgrupador())
                                <span class="fw-semibold">{{ Formato::cantidad($stockAgrupadores[$p->id] ?? 0) }}</span>
                                <small class="text-muted">{{ $p->unidad?->abreviatura }}</small>
                                <div class="small text-muted">en variantes</div>
                            @elseif ($p->manejaStock())
                                @php
                                    $stock = (float) $p->stock_total;
                                    $bajo = (float) $p->stock_minimo > 0 && $stock < (float) $p->stock_minimo;
                                @endphp
                                <span class="fw-semibold {{ $stock < 0 || $bajo ? 'text-danger' : '' }}">{{ Formato::cantidad($stock) }}</span>
                                <small class="text-muted">{{ $p->unidad?->abreviatura }}</small>
                                @if ($bajo)
                                    <i class="bi bi-exclamation-triangle-fill text-danger" title="Bajo el mínimo ({{ Formato::cantidad($p->stock_minimo) }})"></i>
                                @endif
                            @else
                                <span class="text-muted small">No controla</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            @if ($p->manejaStock())
                                <a href="{{ route('inventario.kardex', $p) }}" class="btn btn-sm btn-outline-secondary" title="Kardex"><i class="bi bi-journal-text"></i></a>
                            @endif
                            @if ($puedeEditar)
                                <a href="{{ route('catalogo.productos.edit', $p) }}" class="btn btn-sm btn-outline-secondary" title="Editar"><i class="bi bi-pencil"></i></a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-box-seam fs-1 d-block mb-2"></i>
                            No hay productos que coincidan con los filtros.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($productos->hasPages())
            <div class="card-footer bg-white">{{ $productos->links() }}</div>
        @endif
    </div>
@endsection
