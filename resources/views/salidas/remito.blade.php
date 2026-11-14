{{-- resources/views/salidas/remito.blade.php (detalle + remito imprimible) --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Remito N° ' . $remito->numero_texto)

@push('styles')
<style>
    .solo-impresion { display: none; }
    @media print {
        aside.sidebar, header, .no-imprimir, .btn, .alert { display: none !important; }
        main { padding: 0 !important; }
        .solo-impresion { display: block; }
        .card { box-shadow: none !important; border: 1px solid #ccc !important; }
        body { background: #fff !important; }
        .firma { margin-top: 70px; }
    }
    .marca-anulado { position: absolute; top: 40%; left: 50%; transform: translate(-50%, -50%) rotate(-20deg); font-size: 5rem; font-weight: 800; color: rgba(220, 53, 69, .18); pointer-events: none; }
</style>
@endpush

@section('contenido')
    @php
        // Detalle de lotes / series / ubicaciones que salieron, por producto (desde el kardex).
        $detalle = ($remito->movimiento?->items ?? collect())->groupBy('producto_id');
        $puedeAnular = auth()->user()->tieneRol('admin', 'encargado') && ! $remito->estaAnulado();
    @endphp

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap no-imprimir">
        <a href="{{ route('salidas.remitos.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">Remito N° {{ $remito->numero_texto }}
                @if ($remito->estaAnulado())
                    <span class="badge text-bg-dark align-middle">Anulado</span>
                @else
                    <span class="badge text-bg-success align-middle">Confirmado</span>
                @endif
            </h1>
            <span class="text-muted small">{{ $remito->origen_nombre }} · emitido por {{ $remito->usuario?->name ?? 'sistema' }} el {{ $remito->created_at->format('d/m/Y H:i') }}</span>
        </div>
        <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / PDF</button>
        @if ($remito->movimiento)
            <a href="{{ route('inventario.movimientos.show', $remito->movimiento) }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text"></i> Movimiento</a>
        @endif
        @if ($puedeAnular)
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalAnular"><i class="bi bi-x-octagon"></i> Anular</button>
        @endif
    </div>

    @if ($remito->estaAnulado())
        <div class="alert alert-dark small no-imprimir">
            <i class="bi bi-x-octagon"></i> Anulado el {{ $remito->anulado_at?->format('d/m/Y H:i') }}: {{ $remito->motivo_anulacion }}.
            La mercadería volvió al stock{{ $remito->reserva ? ' y quedó reservada nuevamente' : '' }}.
        </div>
    @endif

    <div class="position-relative">
        @if ($remito->estaAnulado())<div class="marca-anulado">ANULADO</div>@endif

        {{-- Encabezado impreso --}}
        <div class="solo-impresion mb-3">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="h5 mb-0">{{ $empresa->razon_social }}</div>
                    <div class="small">CUIT {{ $empresa->cuit }}{{ $empresa->domicilio ? ' · ' . $empresa->domicilio : '' }}</div>
                    <div class="small">{{ $empresa->telefono }} {{ $empresa->email }}</div>
                </div>
                <div class="text-end">
                    <div class="h5 mb-0">REMITO DE ENTREGA</div>
                    <div>N° {{ $remito->numero_texto }}</div>
                    <div class="small">Fecha {{ $remito->fecha->format('d/m/Y') }}</div>
                    <div class="small fst-italic">Documento no válido como factura</div>
                </div>
            </div>
            <hr>
        </div>

        <div class="card mb-3">
            <div class="card-body row g-3 small">
                <div class="col-md-4">
                    <div class="text-muted">Cliente</div>
                    @if ($remito->cliente)
                        <div class="fw-semibold">{{ $remito->cliente->razon_social }}</div>
                        <div>{{ $remito->cliente->documento ? $remito->cliente->documento_tipo . ' ' . $remito->cliente->documento : '' }}</div>
                        <div class="text-muted">{{ $remito->cliente->telefono }}</div>
                    @else
                        <div class="fw-semibold">Consumidor final</div>
                    @endif
                </div>
                <div class="col-md-4">
                    <div class="text-muted">Entregar en</div>
                    <div class="fw-semibold">{{ $remito->direccion_entrega ?: 'Retira en depósito' }}</div>
                    <div>Sale de: {{ $remito->deposito?->nombre }}</div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted">Fecha / transporte</div>
                    <div class="fw-semibold">{{ $remito->fecha->format('d/m/Y') }}</div>
                    <div>{{ $remito->transportista }}</div>
                    @if ($remito->comprobante_venta)<div>Comprobante: {{ $remito->comprobante_venta }}</div>@endif
                    @if ($remito->reserva)
                        <div class="no-imprimir">Reserva: <a href="{{ route('salidas.reservas.show', $remito->reserva) }}">N° {{ $remito->reserva->numero_texto }}</a></div>
                    @endif
                </div>
                @if ($remito->referencia_externa)
                    <div class="col-md-4 no-imprimir"><div class="text-muted">Referencia externa</div><code>{{ $remito->referencia_externa }}</code></div>
                @endif
                @if ($remito->observaciones)
                    <div class="col-12"><div class="text-muted">Observaciones</div>{{ $remito->observaciones }}</div>
                @endif
            </div>
        </div>

        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                    <tr><th>Código</th><th>Producto</th><th>Lote / series</th><th class="no-imprimir">Ubicación</th><th class="text-end">Cantidad</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($remito->items as $item)
                        @php($partes = $detalle->get($item->producto_id, collect()))
                        <tr>
                            <td><code>{{ $item->producto?->sku }}</code></td>
                            <td class="fw-semibold">{{ $item->producto?->nombre }}</td>
                            <td class="small">
                                @foreach ($partes->filter(fn ($p) => $p->lote) as $p)
                                    <div>Lote {{ $p->lote->codigo }} ({{ Formato::cantidad(abs($p->cantidad)) }})@if ($p->lote->fecha_vencimiento) · vto. {{ $p->lote->fecha_vencimiento->format('d/m/Y') }}@endif</div>
                                @endforeach
                                @php($series = $partes->flatMap->series)
                                @if ($series->isNotEmpty())
                                    <div class="font-monospace">S/N: {{ $series->pluck('numero')->implode(', ') }}</div>
                                @endif
                                @if ($partes->every(fn ($p) => ! $p->lote) && $series->isEmpty())—@endif
                            </td>
                            <td class="small no-imprimir">{{ $partes->map(fn ($p) => $p->ubicacion?->codigo)->filter()->unique()->implode(', ') ?: '—' }}</td>
                            <td class="text-end fw-semibold text-nowrap">{{ Formato::cantidad($item->cantidad) }} {{ $item->producto?->unidad?->abreviatura }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Firmas (solo impresión) --}}
        <div class="solo-impresion">
            <div class="row text-center small firma">
                <div class="col-4"><hr>Entregó</div>
                <div class="col-4"><hr>Recibió conforme (firma)</div>
                <div class="col-4"><hr>Aclaración y DNI</div>
            </div>
        </div>
    </div>

    @if ($puedeAnular)
        <div class="modal fade" id="modalAnular" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('salidas.remitos.anular', $remito) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5">Anular remito N° {{ $remito->numero_texto }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small">Se genera un contramovimiento: la mercadería vuelve al depósito{{ $remito->reserva ? ' y queda reservada otra vez para la reserva N° ' . $remito->reserva->numero_texto : '' }}.</p>
                        <label class="form-label">Motivo *</label>
                        <input type="text" name="motivo" class="form-control" maxlength="255" required placeholder="Ej.: el cliente rechazó la entrega">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                        <button class="btn btn-danger">Anular remito</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
@endsection
