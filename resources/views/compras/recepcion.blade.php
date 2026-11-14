{{-- resources/views/compras/recepcion.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Recepción N° ' . $recepcion->numero_texto)

@section('contenido')
    @php
        $puedeAnular = ! $recepcion->estaAnulada() && auth()->user()->tieneRol('admin', 'encargado');
        $total = $recepcion->items->sum(fn ($i) => (float) $i->cantidad * (float) $i->costo_unitario);
    @endphp

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('compras.recepciones.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">Recepción N° {{ $recepcion->numero_texto }}
                @if ($recepcion->estaAnulada())<span class="badge text-bg-dark align-middle">Anulada</span>@endif
            </h1>
            <span class="text-muted small">
                {{ $recepcion->proveedor?->nombre }} → {{ $recepcion->deposito?->nombre }} ·
                <a href="{{ route('compras.ordenes.show', $recepcion->orden) }}">OC N° {{ $recepcion->orden?->numero_texto }}</a>
            </span>
        </div>
        @if ($recepcion->movimiento)
            <a href="{{ route('inventario.movimientos.show', $recepcion->movimiento) }}" class="btn btn-light"><i class="bi bi-arrow-left-right"></i> Movimiento de stock</a>
        @endif
        @if ($puedeAnular)
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalAnular"><i class="bi bi-x-octagon"></i> Anular</button>
        @endif
    </div>

    @if ($recepcion->estaAnulada())
        <div class="alert alert-dark">
            Anulada el {{ $recepcion->anulada_at?->format('d/m/Y H:i') }}. El stock salió y la orden volvió a esperar esa mercadería.
            @if ($recepcion->motivo_anulacion)<div class="small">Motivo: {{ $recepcion->motivo_anulacion }}</div>@endif
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body row g-3 small">
            <div class="col-md-3"><div class="text-muted">Fecha</div><div class="fw-semibold">{{ $recepcion->fecha->format('d/m/Y') }}</div></div>
            <div class="col-md-3"><div class="text-muted">Remito</div><div class="fw-semibold">{{ $recepcion->remito ?? '—' }}</div></div>
            <div class="col-md-3"><div class="text-muted">Factura</div><div class="fw-semibold">{{ $recepcion->factura ?? '—' }}</div></div>
            <div class="col-md-3"><div class="text-muted">Recibió</div><div class="fw-semibold">{{ $recepcion->usuario?->name }}</div></div>
            @if ($recepcion->observaciones)<div class="col-12"><div class="text-muted">Observaciones</div>{{ $recepcion->observaciones }}</div>@endif
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr><th>Producto</th><th>Lote / series / ubicación</th><th class="text-end">Cantidad</th><th class="text-end">Costo unit.</th><th class="text-end">Subtotal</th></tr>
                </thead>
                <tbody>
                @foreach ($recepcion->items as $item)
                    @php($renglones = $recepcion->movimiento?->items->where('producto_id', $item->producto_id) ?? collect())
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $item->producto?->nombre }}</div>
                            <div class="small text-muted"><code>{{ $item->producto?->sku }}</code></div>
                        </td>
                        <td class="small">
                            @foreach ($renglones->where('cantidad', '>', 0) as $r)
                                <div>
                                    @if ($r->lote)Lote {{ $r->lote->codigo }}@if ($r->lote->fecha_vencimiento) (vence {{ $r->lote->fecha_vencimiento->format('d/m/Y') }})@endif · @endif
                                    {{ $r->ubicacion?->codigo ?? 'Sin ubicar' }}
                                    @if ($r->series->isNotEmpty())
                                        <div class="font-monospace text-muted">{{ $r->series->pluck('numero')->implode(', ') }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </td>
                        <td class="text-end fw-semibold">{{ Formato::cantidad($item->cantidad) }} {{ $item->producto?->unidad?->abreviatura }}</td>
                        <td class="text-end">{{ Formato::moneda($item->costo_unitario) }}</td>
                        <td class="text-end">{{ Formato::moneda((float) $item->cantidad * (float) $item->costo_unitario) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light">
                <tr><th colspan="4" class="text-end">Total (sin IVA)</th><th class="text-end">{{ Formato::moneda($total) }}</th></tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if ($puedeAnular)
        <div class="modal fade" id="modalAnular" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('compras.recepciones.anular', $recepcion) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5">Anular recepción N° {{ $recepcion->numero_texto }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">El stock que entró va a salir (si todavía está) y la orden vuelve a quedar pendiente por esas cantidades.</p>
                        <label class="form-label">Motivo *</label>
                        <input type="text" name="motivo" class="form-control" maxlength="255" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                        <button class="btn btn-danger">Anular</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
