{{-- resources/views/inventario/serie.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Serie ' . $serie->numero)

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('inventario.series.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0 font-monospace">{{ $serie->numero }}</h1>
            <span class="text-muted small">{{ $serie->producto?->nombre }} · <code>{{ $serie->producto?->sku }}</code></span>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Estado</div>
                    <div class="fs-5 fw-semibold">{{ $serie->estado_nombre }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Dónde está</div>
                    <div class="fs-5 fw-semibold">{{ $serie->deposito?->nombre ?? 'Fuera del stock' }}</div>
                    @if ($serie->ubicacion)<div class="small">Ubicación {{ $serie->ubicacion->codigo }}</div>@endif
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Lote</div>
                    <div class="fs-5 fw-semibold">{{ $serie->lote?->codigo ?? '—' }}</div>
                    @if ($serie->lote?->fecha_vencimiento)<div class="small">Vence {{ $serie->lote->fecha_vencimiento->format('d/m/Y') }}</div>@endif
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history"></i> Historial</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Movimiento</th>
                    <th>Depósito</th>
                    <th>Ubicación</th>
                    <th class="text-center">Entra / sale</th>
                    <th>Usuario</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($serie->items as $item)
                    @php($mov = $item->movimiento)
                    <tr class="{{ $mov?->estaAnulado() ? 'text-muted' : '' }}">
                        <td class="small text-nowrap">{{ $item->fecha->format('d/m/Y H:i') }}</td>
                        <td>
                            <a href="{{ route('inventario.movimientos.show', $mov) }}" class="text-decoration-none">
                                <span class="badge text-bg-{{ $mov->color }}">{{ $mov->tipo_nombre }}</span>
                                <span class="small">N° {{ $mov->numero_texto }}</span>
                            </a>
                            @if ($mov->motivo || $mov->comprobante)
                                <div class="small text-muted">{{ collect([$mov->motivo?->nombre, $mov->comprobante])->filter()->implode(' · ') }}</div>
                            @endif
                        </td>
                        <td class="small">{{ $item->deposito?->nombre }}</td>
                        <td class="small">{{ $item->ubicacion?->codigo ?? '—' }}</td>
                        <td class="text-center">
                            @if ((float) $item->cantidad > 0)
                                <span class="text-success"><i class="bi bi-box-arrow-in-down"></i> Entra</span>
                            @else
                                <span class="text-danger"><i class="bi bi-box-arrow-up"></i> Sale</span>
                            @endif
                        </td>
                        <td class="small">{{ $mov->usuario?->name ?? $mov->origen_nombre }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Sin movimientos.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
