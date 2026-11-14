{{-- resources/views/salidas/reservas.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Reservas')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Reservas de stock</h1>
            <span class="text-muted small">Mercadería comprometida para clientes: sigue en el depósito pero no está disponible para otras salidas.</span>
        </div>
        <a href="{{ route('salidas.reservas.create') }}" class="btn btn-stk"><i class="bi bi-bookmark-plus"></i> Nueva reserva</a>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2">
            <div class="col-md-5">
                <select name="estado" class="form-select">
                    @foreach ($estados as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ $texto }}</option>
                    @endforeach
                    <option value="todas" @selected($filtros['estado'] === 'todas')>Todas</option>
                </select>
            </div>
            <div class="col-md-5">
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
                <tr><th>N°</th><th>Cliente</th><th>Depósito</th><th>Origen</th><th>Vence</th><th class="text-center">Productos</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($reservas as $r)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('salidas.reservas.show', $r) }}" class="text-decoration-none">{{ $r->numero_texto }}</a></td>
                        <td>{{ $r->cliente?->nombre ?? '—' }}</td>
                        <td class="small">{{ $r->deposito?->nombre }}</td>
                        <td class="small">{{ $r->origen_nombre }} @if ($r->referencia_externa)<div class="text-muted"><code>{{ $r->referencia_externa }}</code></div>@endif</td>
                        <td class="small {{ $r->estaActiva() && $r->vence_at?->isPast() ? 'text-danger' : '' }}">{{ $r->vence_at?->format('d/m/Y H:i') ?? 'Sin vencimiento' }}</td>
                        <td class="text-center">{{ $r->items->count() }}</td>
                        <td><span class="badge text-bg-{{ $r->color }}">{{ $r->estado_nombre }}</span></td>
                        <td class="text-end text-nowrap">
                            @if ($r->estaActiva())
                                <a href="{{ route('salidas.remitos.create', ['reserva' => $r->id]) }}" class="btn btn-sm btn-success" title="Entregar"><i class="bi bi-truck"></i></a>
                            @endif
                            <a href="{{ route('salidas.reservas.show', $r) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-bookmark fs-1 d-block mb-2"></i>No hay reservas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($reservas->hasPages())<div class="card-footer bg-white">{{ $reservas->links() }}</div>@endif
    </div>
@endsection
