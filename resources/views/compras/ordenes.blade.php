{{-- resources/views/compras/ordenes.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Órdenes de compra')

@section('contenido')
    @php($puedeEditar = auth()->user()->tieneRol('admin', 'encargado'))

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">Órdenes de compra</h1>
        @if ($puedeEditar)
            <div class="d-flex gap-2">
                <a href="{{ route('compras.reposicion.index') }}" class="btn btn-outline-secondary"><i class="bi bi-cart-plus"></i> Sugerencia de reposición</a>
                <a href="{{ route('compras.ordenes.create') }}" class="btn btn-stk"><i class="bi bi-plus-lg"></i> Nueva orden</a>
            </div>
        @endif
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">N°</label>
                <input type="search" name="q" class="form-control" value="{{ $filtros['q'] }}">
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small mb-1">Estado</label>
                <select name="estado" class="form-select">
                    <option value="abiertas" @selected($filtros['estado'] === 'abiertas')>Abiertas (esperando mercadería)</option>
                    <option value="todas" @selected($filtros['estado'] === 'todas')>Todas</option>
                    @foreach ($estados as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small mb-1">Proveedor</label>
                <select name="proveedor_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($proveedores as $p)
                        <option value="{{ $p->id }}" @selected($filtros['proveedor_id'] === $p->id)>{{ $p->nombre }}</option>
                    @endforeach
                </select>
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
                    <th>N°</th>
                    <th>Fecha</th>
                    <th>Proveedor</th>
                    <th>Recibe en</th>
                    <th>Estado</th>
                    <th style="width: 140px;">Recibido</th>
                    <th class="text-end">Total</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($ordenes as $o)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('compras.ordenes.show', $o) }}" class="text-decoration-none">{{ $o->numero_texto }}</a></td>
                        <td class="small text-nowrap">
                            {{ $o->fecha->format('d/m/Y') }}
                            @if ($o->fecha_entrega)
                                <div class="{{ $o->estaAbierta() && $o->fecha_entrega->isPast() ? 'text-danger' : 'text-muted' }}">Entrega {{ $o->fecha_entrega->format('d/m') }}</div>
                            @endif
                        </td>
                        <td>{{ $o->proveedor?->nombre }}</td>
                        <td class="small">{{ $o->deposito?->nombre }}</td>
                        <td><span class="badge text-bg-{{ $o->color }} {{ $o->color === 'light' ? 'border' : '' }}">{{ $o->estado_nombre }}</span></td>
                        <td>
                            <div class="progress" style="height: 8px;" title="{{ $o->avance }}%">
                                <div class="progress-bar bg-success" style="width: {{ $o->avance }}%"></div>
                            </div>
                            <span class="small text-muted">{{ $o->avance }}%</span>
                        </td>
                        <td class="text-end">{{ Formato::moneda($o->total) }}</td>
                        <td class="text-end text-nowrap">
                            @if ($o->estaAbierta() && $o->avance < 100)
                                <a href="{{ route('compras.recepciones.create', $o) }}" class="btn btn-sm btn-success" title="Recibir"><i class="bi bi-box-arrow-in-down"></i></a>
                            @endif
                            <a href="{{ route('compras.ordenes.show', $o) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-receipt fs-1 d-block mb-2"></i>No hay órdenes para los filtros elegidos.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($ordenes->hasPages())
            <div class="card-footer bg-white">{{ $ordenes->links() }}</div>
        @endif
    </div>
@endsection
