{{-- resources/views/salidas/reserva.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Reserva N° ' . $reserva->numero_texto)

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('salidas.reservas.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">Reserva N° {{ $reserva->numero_texto }}
                <span class="badge text-bg-{{ $reserva->color }} align-middle">{{ $reserva->estado_nombre }}</span>
            </h1>
            <span class="text-muted small">{{ $reserva->cliente?->nombre ?? 'Sin cliente' }} · {{ $reserva->deposito?->nombre }} · {{ $reserva->origen_nombre }}</span>
        </div>
        @if ($reserva->estaActiva())
            <a href="{{ route('salidas.remitos.create', ['reserva' => $reserva->id]) }}" class="btn btn-success"><i class="bi bi-truck"></i> Entregar (remito)</a>
            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalLiberar"><i class="bi bi-unlock"></i> Liberar</button>
        @endif
    </div>

    <div class="card mb-3">
        <div class="card-body row g-3 small">
            <div class="col-md-3"><div class="text-muted">Creada</div><div class="fw-semibold">{{ $reserva->created_at->format('d/m/Y H:i') }}</div><div>{{ $reserva->usuario?->name }}</div></div>
            <div class="col-md-3"><div class="text-muted">Vence</div><div class="fw-semibold {{ $reserva->estaActiva() && $reserva->vence_at?->isPast() ? 'text-danger' : '' }}">{{ $reserva->vence_at?->format('d/m/Y H:i') ?? 'No vence' }}</div></div>
            @if ($reserva->referencia_externa)<div class="col-md-3"><div class="text-muted">Referencia</div><code>{{ $reserva->referencia_externa }}</code></div>@endif
            @if ($reserva->cerrada_at)<div class="col-md-3"><div class="text-muted">Cerrada</div><div class="fw-semibold">{{ $reserva->cerrada_at->format('d/m/Y H:i') }}</div><div>{{ $reserva->motivo_cierre }}</div></div>@endif
            @if ($reserva->observaciones)<div class="col-12"><div class="text-muted">Observaciones</div>{{ $reserva->observaciones }}</div>@endif
        </div>
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr><th>Producto</th><th>Lote</th><th class="text-end">Reservado</th><th class="text-end">Entregado</th><th class="text-end">Liberado</th><th class="text-end">Pendiente</th></tr>
                </thead>
                <tbody>
                @foreach ($reserva->items as $item)
                    @php($u = $item->producto?->unidad?->abreviatura)
                    <tr>
                        <td><div class="fw-semibold">{{ $item->producto?->nombre }}</div><div class="small text-muted"><code>{{ $item->producto?->sku }}</code></div></td>
                        <td class="small">{{ $item->lote?->codigo ?? '—' }}</td>
                        <td class="text-end">{{ Formato::cantidad($item->cantidad) }} {{ $u }}</td>
                        <td class="text-end text-success">{{ Formato::cantidad($item->cantidad_entregada) }}</td>
                        <td class="text-end text-muted">{{ Formato::cantidad($item->cantidad_liberada) }}</td>
                        <td class="text-end fw-semibold {{ $item->pendiente > 0 ? 'text-warning-emphasis' : '' }}">{{ Formato::cantidad($item->pendiente) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-semibold">Remitos de esta reserva</div>
        <ul class="list-group list-group-flush">
            @forelse ($reserva->remitos as $r)
                <li class="list-group-item d-flex justify-content-between">
                    <a href="{{ route('salidas.remitos.show', $r) }}">Remito N° {{ $r->numero_texto }}</a>
                    <span class="small text-muted">{{ $r->fecha->format('d/m/Y') }} @if ($r->estaAnulado())<span class="badge text-bg-dark">Anulado</span>@endif</span>
                </li>
            @empty
                <li class="list-group-item text-muted small">Todavía no se entregó nada.</li>
            @endforelse
        </ul>
    </div>

    @if ($reserva->estaActiva())
        <div class="modal fade" id="modalLiberar" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('salidas.reservas.liberar', $reserva) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5">Liberar stock reservado</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">Lo liberado vuelve a estar disponible para cualquier salida.</p>
                        <table class="table table-sm align-middle">
                            @foreach ($reserva->items as $item)
                                @continue($item->pendiente <= 0)
                                <tr>
                                    <td class="small">{{ $item->producto?->nombre }} {{ $item->lote ? '· ' . $item->lote->codigo : '' }}</td>
                                    <td style="width: 130px;">
                                        <input type="number" name="cantidades[{{ $item->id }}]" class="form-control form-control-sm" min="0" max="{{ $item->pendiente }}" step="any" value="{{ $item->pendiente }}">
                                    </td>
                                </tr>
                            @endforeach
                        </table>
                        <label class="form-label">Motivo</label>
                        <input type="text" name="motivo" class="form-control" maxlength="255" placeholder="Ej.: el cliente canceló">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                        <button class="btn btn-outline-secondary" name="todo" value="1">Liberar todo</button>
                        <button class="btn btn-stk">Liberar lo indicado</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
