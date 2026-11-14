{{-- resources/views/inventario/transferencias.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Transferencias')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">Transferencias entre depósitos</h1>
        @if (auth()->user()->tieneRol('admin', 'encargado', 'operador'))
            <a href="{{ route('inventario.transferencias.create') }}" class="btn btn-stk"><i class="bi bi-truck"></i> Nueva transferencia</a>
        @endif
    </div>

    @if ($pendientes)
        <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span><i class="bi bi-truck"></i> Tenés <strong>{{ $pendientes }}</strong> {{ $pendientes === 1 ? 'transferencia' : 'transferencias' }} en camino a tus depósitos.</span>
            <a href="{{ route('inventario.transferencias.index', ['estado' => 'en_transito']) }}" class="btn btn-sm btn-warning">Ver pendientes</a>
        </div>
    @endif

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-lg-4">
                <label class="form-label small mb-1">Buscar</label>
                <input type="search" name="q" class="form-control" placeholder="N°, transportista, observaciones…" value="{{ $filtros['q'] }}">
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small mb-1">Depósito (origen o destino)</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small mb-1">Estado</label>
                <select name="estado" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($estados as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i> Filtrar</button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>N°</th>
                    <th>Enviada</th>
                    <th>Origen → Destino</th>
                    <th class="text-center">Productos</th>
                    <th>Estado</th>
                    <th>Transportista</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($transferencias as $t)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('inventario.transferencias.show', $t) }}" class="text-decoration-none">{{ $t->numero_texto }}</a></td>
                        <td class="small text-nowrap">
                            {{ $t->enviada_at->format('d/m/Y H:i') }}
                            <div class="text-muted">{{ $t->enviadaPor?->name }}</div>
                        </td>
                        <td class="small">{{ $t->origen?->nombre }} <i class="bi bi-arrow-right"></i> <strong>{{ $t->destino?->nombre }}</strong></td>
                        <td class="text-center">{{ $t->items_count }}</td>
                        <td><span class="badge text-bg-{{ $t->color }}">{{ $t->estado_nombre }}</span></td>
                        <td class="small">{{ $t->transportista }}</td>
                        <td class="text-end text-nowrap">
                            @if ($t->puedeRecibirla(auth()->user()))
                                <a href="{{ route('inventario.transferencias.recepcion', $t) }}" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down"></i> Recibir</a>
                            @endif
                            <a href="{{ route('inventario.transferencias.show', $t) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-truck fs-1 d-block mb-2"></i>
                            No hay transferencias para los filtros elegidos.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($transferencias->hasPages())
            <div class="card-footer bg-white">{{ $transferencias->links() }}</div>
        @endif
    </div>
@endsection
