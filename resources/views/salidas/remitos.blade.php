{{-- resources/views/salidas/remitos.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Remitos')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Remitos de entrega</h1>
            <span class="text-muted small">Cada remito descuenta el stock y queda en el kardex como egreso.</span>
        </div>
        <a href="{{ route('salidas.remitos.create') }}" class="btn btn-stk"><i class="bi bi-truck"></i> Nuevo remito</a>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2">
            <div class="col-md-4"><input type="search" name="q" class="form-control" placeholder="N° de remito" value="{{ $filtros['q'] }}"></div>
            <div class="col-md-6">
                <select name="deposito_id" class="form-select">
                    <option value="">Todos los depósitos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid"><button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>N°</th><th>Fecha</th><th>Cliente</th><th>Depósito</th><th>Origen</th><th class="text-center">Productos</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($remitos as $r)
                    <tr class="{{ $r->estaAnulado() ? 'text-muted' : '' }}">
                        <td class="fw-semibold"><a href="{{ route('salidas.remitos.show', $r) }}" class="text-decoration-none">{{ $r->numero_texto }}</a></td>
                        <td>{{ $r->fecha->format('d/m/Y') }}</td>
                        <td>
                            {{ $r->cliente?->nombre ?? '—' }}
                            @if ($r->reserva)<div class="small text-muted">Reserva N° {{ $r->reserva->numero_texto }}</div>@endif
                        </td>
                        <td class="small">{{ $r->deposito?->nombre }}</td>
                        <td class="small">{{ $r->origen_nombre }} @if ($r->comprobante_venta)<div class="text-muted">{{ $r->comprobante_venta }}</div>@endif</td>
                        <td class="text-center">{{ $r->items_count }}</td>
                        <td>
                            @if ($r->estaAnulado())
                                <span class="badge text-bg-dark">Anulado</span>
                            @else
                                <span class="badge text-bg-success">Confirmado</span>
                            @endif
                        </td>
                        <td class="text-end"><a href="{{ route('salidas.remitos.show', $r) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-truck fs-1 d-block mb-2"></i>Todavía no hay remitos.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($remitos->hasPages())<div class="card-footer bg-white">{{ $remitos->links() }}</div>@endif
    </div>
@endsection
