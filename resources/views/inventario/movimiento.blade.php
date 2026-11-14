{{-- resources/views/inventario/movimiento.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Movimiento N° ' . $movimiento->numero_texto)

@section('contenido')
    @php
        $esTransferencia = (bool) $movimiento->deposito_destino_id;
        $total = $movimiento->items->sum(fn ($i) => $i->subtotal);
    @endphp

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('inventario.movimientos.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">
                {{ $movimiento->tipo_nombre }} N° {{ $movimiento->numero_texto }}
                @if ($movimiento->estaAnulado())
                    <span class="badge text-bg-dark align-middle">Anulado</span>
                @endif
            </h1>
            <span class="text-muted small">{{ $movimiento->fecha->format('d/m/Y H:i') }} · {{ $movimiento->usuario?->name ?? $movimiento->origen_nombre }}</span>
        </div>
        <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir</button>
        @if ($puedeAnular)
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalAnular">
                <i class="bi bi-x-octagon"></i> Anular
            </button>
        @endif
    </div>

    @if ($movimiento->estaAnulado())
        <div class="alert alert-dark">
            Anulado el {{ $movimiento->anulado_at?->format('d/m/Y H:i') }}
            @if ($movimiento->usuarioAnulacion) por {{ $movimiento->usuarioAnulacion->name }} @endif
            @if ($movimiento->anuladoPor)
                con el contramovimiento
                <a href="{{ route('inventario.movimientos.show', $movimiento->anuladoPor) }}" class="alert-link">N° {{ $movimiento->anuladoPor->numero_texto }}</a>.
            @endif
            @if ($movimiento->motivo_anulacion)
                <div class="small mt-1">Motivo: {{ $movimiento->motivo_anulacion }}</div>
            @endif
        </div>
    @endif

    @if ($movimiento->transferencia)
        <div class="alert alert-info">
            <i class="bi bi-truck"></i> Generado por la
            <a href="{{ route('inventario.transferencias.show', $movimiento->transferencia) }}" class="alert-link">transferencia N° {{ $movimiento->transferencia->numero_texto }}</a>.
            Se gestiona desde ahí.
        </div>
    @endif

    @if ($movimiento->recepcionCompra)
        <div class="alert alert-info">
            <i class="bi bi-cart-check"></i> Generado por la
            <a href="{{ route('compras.recepciones.show', $movimiento->recepcionCompra) }}" class="alert-link">recepción N° {{ $movimiento->recepcionCompra->numero_texto }}</a>
            de la orden de compra N° {{ $movimiento->recepcionCompra->orden?->numero_texto }}. Se gestiona desde Compras.
        </div>
    @endif

    @if ($movimiento->remito)
        <div class="alert alert-info">
            <i class="bi bi-truck"></i> Generado por el
            <a href="{{ route('salidas.remitos.show', $movimiento->remito) }}" class="alert-link">remito N° {{ $movimiento->remito->numero_texto }}</a>{{ $movimiento->remito->cliente ? ' a ' . $movimiento->remito->cliente->nombre : '' }}.
            Para revertirlo, anulá el remito.
        </div>
    @endif

    @if ($movimiento->anulaA)
        <div class="alert alert-secondary">
            Este movimiento anula al
            <a href="{{ route('inventario.movimientos.show', $movimiento->anulaA) }}" class="alert-link">
                {{ $movimiento->anulaA->tipo_nombre }} N° {{ $movimiento->anulaA->numero_texto }}
            </a>.
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body row g-3 small">
            <div class="col-md-3">
                <div class="text-muted">{{ $esTransferencia ? 'Depósito de origen' : 'Depósito' }}</div>
                <div class="fw-semibold">{{ $movimiento->deposito?->nombre }}</div>
            </div>
            @if ($esTransferencia)
                <div class="col-md-3">
                    <div class="text-muted">Depósito de destino</div>
                    <div class="fw-semibold">{{ $movimiento->depositoDestino?->nombre }}</div>
                </div>
            @endif
            @if ($movimiento->motivo)
                <div class="col-md-3">
                    <div class="text-muted">Motivo</div>
                    <div class="fw-semibold">{{ $movimiento->motivo->nombre }}</div>
                </div>
            @endif
            <div class="col-md-3">
                <div class="text-muted">Origen</div>
                <div class="fw-semibold">{{ $movimiento->origen_nombre }}</div>
            </div>
            @if ($movimiento->comprobante)
                <div class="col-md-3">
                    <div class="text-muted">Comprobante</div>
                    <div class="fw-semibold">
                        {{ $movimiento->comprobante }}
                        @if ($movimiento->fecha_comprobante) · {{ $movimiento->fecha_comprobante->format('d/m/Y') }} @endif
                    </div>
                </div>
            @endif
            @if ($movimiento->referencia_externa)
                <div class="col-md-3">
                    <div class="text-muted">Referencia externa</div>
                    <div class="fw-semibold"><code>{{ $movimiento->referencia_externa }}</code></div>
                </div>
            @endif
            @if ($movimiento->observaciones)
                <div class="col-12">
                    <div class="text-muted">Observaciones</div>
                    <div>{{ $movimiento->observaciones }}</div>
                </div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Producto</th>
                    @if ($esTransferencia)<th>Depósito</th>@endif
                    <th>Ubicación</th>
                    <th class="text-end">Cantidad</th>
                    <th class="text-end">Costo unit.</th>
                    <th class="text-end">Subtotal</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($movimiento->items as $item)
                    <tr>
                        <td>
                            <a href="{{ route('inventario.kardex', $item->producto_id) }}" class="fw-semibold text-decoration-none">{{ $item->producto?->nombre }}</a>
                            <div class="small text-muted"><code>{{ $item->producto?->sku }}</code></div>
                            @if ($item->lote)
                                <div class="small"><span class="badge text-bg-light border">Lote {{ $item->lote->codigo }}</span>
                                    @if ($item->lote->fecha_vencimiento)<span class="text-muted">vence {{ $item->lote->fecha_vencimiento->format('d/m/Y') }}</span>@endif
                                </div>
                            @endif
                            @if ($item->series->isNotEmpty())
                                <div class="small text-muted">
                                    Series:
                                    @foreach ($item->series as $serie)
                                        <a href="{{ route('inventario.series.show', $serie) }}" class="badge text-bg-light border text-decoration-none font-monospace">{{ $serie->numero }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        @if ($esTransferencia)<td class="small">{{ $item->deposito?->nombre }}</td>@endif
                        <td class="small">{{ $item->ubicacion?->codigo ?? '—' }}</td>
                        <td class="text-end fw-semibold {{ (float) $item->cantidad < 0 ? 'text-danger' : 'text-success' }}">
                            {{ Formato::cantidadConSigno($item->cantidad) }} {{ $item->producto?->unidad?->abreviatura }}
                            @if ($item->presentacion)
                                <div class="small text-muted fw-normal">{{ Formato::cantidad($item->cantidad_presentacion) }} × {{ $item->presentacion->nombre }}</div>
                            @endif
                            @if ($item->cantidad_contada !== null)
                                <div class="small text-muted fw-normal">
                                    Contado {{ Formato::cantidad($item->cantidad_contada) }} · sistema {{ Formato::cantidad((float) $item->cantidad_contada - (float) $item->cantidad) }}
                                </div>
                            @endif
                        </td>
                        <td class="text-end">{{ Formato::moneda($item->costo_unitario) }}</td>
                        <td class="text-end">{{ Formato::moneda($item->subtotal) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light">
                <tr>
                    <th colspan="{{ $esTransferencia ? 5 : 4 }}" class="text-end">Total valorizado</th>
                    <th class="text-end">{{ Formato::moneda($total) }}</th>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if ($puedeAnular)
        <div class="modal fade" id="modalAnular" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('inventario.movimientos.anular', $movimiento) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5">Anular movimiento N° {{ $movimiento->numero_texto }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            No se borra nada: se genera un contramovimiento que revierte cada renglón y queda registrado en el kardex.
                        </p>
                        <label class="form-label">Motivo *</label>
                        <input type="text" name="motivo" class="form-control" maxlength="255" required value="{{ old('motivo') }}">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                        <button class="btn btn-danger"><i class="bi bi-x-octagon"></i> Anular</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection

@push('styles')
<style>
    @media print {
        aside.sidebar, header, .btn, .alert-dismissible { display: none !important; }
        main { padding: 0 !important; }
    }
</style>
@endpush
