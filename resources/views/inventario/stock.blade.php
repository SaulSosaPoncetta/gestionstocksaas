{{-- resources/views/inventario/stock.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Stock')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Stock</h1>
            <span class="text-muted small">
                {{ $filtros['deposito_id'] ? $depositos->firstWhere('id', $filtros['deposito_id'])?->nombre : 'Todos tus depósitos' }}
                · Valor a costo: <strong>{{ Formato::moneda($valorTotal) }}</strong>
            </span>
        </div>
        @if (auth()->user()->tieneRol('admin', 'encargado'))
            <a href="{{ route('inventario.movimientos.create', 'inicial') }}" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-in-down"></i> Cargar stock inicial
            </a>
        @endif
    </div>

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
                <label class="form-label small mb-1">Depósito</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
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
            <div class="col-8 col-lg-3">
                <label class="form-label small mb-1">Mostrar</label>
                <select name="estado" class="form-select">
                    @foreach ($estados as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-lg-1 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Producto</th>
                    <th>Categoría</th>
                    <th class="text-end">Stock</th>
                    <th class="text-end">Reservado</th>
                    <th class="text-end">Disponible</th>
                    <th class="text-end">Mín. / Reposición</th>
                    <th class="text-end">Valor</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($productos as $p)
                    @php
                        $stock = (float) $p->stock;
                        $reservado = (float) $p->reservado;
                        $minimo = (float) $p->stock_minimo;
                        $reponer = $p->punto_reposicion !== null ? (float) $p->punto_reposicion : null;
                        $clase = $stock < 0 ? 'text-danger' : ($minimo > 0 && $stock < $minimo ? 'text-danger' : ($reponer !== null && $stock <= $reponer ? 'text-warning-emphasis' : ''));
                    @endphp
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $p->nombre }}</div>
                            <div class="small text-muted"><code>{{ $p->sku }}</code></div>
                        </td>
                        <td class="small">{{ $p->categoria?->nombre }}</td>
                        <td class="text-end fw-semibold {{ $clase }}">
                            {{ Formato::cantidad($stock) }} <small class="text-muted fw-normal">{{ $p->unidad?->abreviatura }}</small>
                            @if ($minimo > 0 && $stock < $minimo)
                                <i class="bi bi-exclamation-triangle-fill text-danger" title="Bajo el mínimo"></i>
                            @elseif ($reponer !== null && $stock <= $reponer)
                                <i class="bi bi-cart-plus text-warning" title="En punto de reposición"></i>
                            @endif
                        </td>
                        <td class="text-end text-muted">{{ $reservado ? Formato::cantidad($reservado) : '—' }}</td>
                        <td class="text-end">{{ Formato::cantidad($stock - $reservado) }}</td>
                        <td class="text-end small text-muted">
                            {{ $minimo ? Formato::cantidad($minimo) : '—' }} / {{ $reponer !== null ? Formato::cantidad($reponer) : '—' }}
                        </td>
                        <td class="text-end small">{{ Formato::moneda($stock * $p->costo_valuacion) }}</td>
                        <td class="text-end">
                            <a href="{{ route('inventario.kardex', ['producto' => $p, 'deposito_id' => $filtros['deposito_id']]) }}" class="btn btn-sm btn-outline-secondary" title="Kardex">
                                <i class="bi bi-journal-text"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-clipboard-data fs-1 d-block mb-2"></i>
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
