{{-- resources/views/inventario/series.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Números de serie')

@section('contenido')
    <h1 class="h4 mb-3">Números de serie</h1>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-lg-5">
                <label class="form-label small mb-1">Número de serie o producto</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                    <input type="search" name="q" class="form-control" placeholder="Escaneá o escribí el número" value="{{ $filtros['q'] }}" autofocus>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small mb-1">Depósito</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Estado</label>
                <select name="estado" class="form-select">
                    <option value="">Todos</option>
                    <option value="en_stock" @selected($filtros['estado'] === 'en_stock')>En stock</option>
                    <option value="egresado" @selected($filtros['estado'] === 'egresado')>Egresados</option>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i> Buscar</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Serie</th>
                    <th>Producto</th>
                    <th>Estado</th>
                    <th>Dónde está</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($series as $s)
                    <tr>
                        <td class="font-monospace fw-semibold">{{ $s->numero }}</td>
                        <td>
                            <div>{{ $s->producto?->nombre }}</div>
                            <div class="small text-muted"><code>{{ $s->producto?->sku }}</code></div>
                        </td>
                        <td><span class="badge text-bg-{{ $s->enStock() ? ($s->deposito?->es_transito ? 'warning' : 'success') : 'secondary' }}">{{ $s->estado_nombre }}</span></td>
                        <td class="small">
                            {{ $s->deposito?->nombre ?? '—' }}
                            @if ($s->ubicacion) · {{ $s->ubicacion->codigo }} @endif
                            @if ($s->lote) · Lote {{ $s->lote->codigo }} @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('inventario.series.show', $s) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-clock-history"></i> Historial</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-upc fs-1 d-block mb-2"></i>
                            {{ $filtros['q'] !== '' ? 'No se encontró ese número de serie.' : 'Todavía no hay números de serie registrados.' }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($series->hasPages())
            <div class="card-footer bg-white">{{ $series->links() }}</div>
        @endif
    </div>
@endsection
