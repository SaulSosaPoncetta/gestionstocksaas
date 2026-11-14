{{-- resources/views/inventario/transferencia.blade.php (detalle + remito imprimible) --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Transferencia N° ' . $transferencia->numero_texto)

@push('styles')
<style>
    .solo-impresion { display: none; }
    @media print {
        aside.sidebar, header, .no-imprimir, .btn, .alert { display: none !important; }
        main { padding: 0 !important; }
        .solo-impresion { display: block; }
        .card { box-shadow: none !important; border: 1px solid #ccc !important; }
        body { background: #fff !important; }
    }
    .firma { border-top: 1px solid #333; padding-top: .3rem; margin-top: 3.5rem; text-align: center; font-size: .85rem; }
</style>
@endpush

@section('contenido')
    @php($recibida = $transferencia->recibida_at !== null)

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap no-imprimir">
        <a href="{{ route('inventario.transferencias.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">
                Transferencia N° {{ $transferencia->numero_texto }}
                <span class="badge text-bg-{{ $transferencia->color }} align-middle">{{ $transferencia->estado_nombre }}</span>
            </h1>
            <span class="text-muted small">{{ $transferencia->origen?->nombre }} → {{ $transferencia->destino?->nombre }}</span>
        </div>
        <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir remito</button>
        @if ($puedeRecibir)
            <a href="{{ route('inventario.transferencias.recepcion', $transferencia) }}" class="btn btn-success"><i class="bi bi-box-arrow-in-down"></i> Recibir</a>
        @endif
        @if ($puedeAnular)
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalAnular"><i class="bi bi-x-octagon"></i> Anular</button>
        @endif
    </div>

    {{-- Encabezado del remito (solo al imprimir) --}}
    <div class="solo-impresion mb-3">
        <div class="d-flex justify-content-between">
            <div>
                <div class="h5 mb-0">{{ $empresa->razon_social }}</div>
                <div class="small">CUIT {{ $empresa->cuit }}{{ $empresa->domicilio ? ' · ' . $empresa->domicilio : '' }}</div>
            </div>
            <div class="text-end">
                <div class="h5 mb-0">REMITO DE TRANSFERENCIA INTERNA</div>
                <div>N° {{ $transferencia->numero_texto }}</div>
                <div class="small">Documento no válido como factura</div>
            </div>
        </div>
        <hr>
    </div>

    @if ($transferencia->estado === 'anulada')
        <div class="alert alert-secondary">
            Anulada el {{ $transferencia->anulada_at?->format('d/m/Y H:i') }} por {{ $transferencia->anuladaPor?->name ?? 'el sistema' }}.
            La mercadería volvió a {{ $transferencia->origen?->nombre }}.
            @if ($transferencia->motivo_anulacion)<div class="small">Motivo: {{ $transferencia->motivo_anulacion }}</div>@endif
        </div>
    @elseif ($transferencia->enTransito())
        <div class="alert alert-warning">
            <i class="bi bi-truck"></i> La mercadería salió de {{ $transferencia->origen?->nombre }} y está en camino.
            El stock va a entrar en {{ $transferencia->destino?->nombre }} cuando la reciban.
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-body row g-3 small">
            <div class="col-md-3">
                <div class="text-muted">Origen</div>
                <div class="fw-semibold">{{ $transferencia->origen?->nombre }}</div>
                <div class="text-muted">{{ $transferencia->origen?->direccion }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted">Destino</div>
                <div class="fw-semibold">{{ $transferencia->destino?->nombre }}</div>
                <div class="text-muted">{{ $transferencia->destino?->direccion }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted">Enviada</div>
                <div class="fw-semibold">{{ $transferencia->enviada_at->format('d/m/Y H:i') }}</div>
                <div class="text-muted">{{ $transferencia->enviadaPor?->name }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted">Recibida</div>
                @if ($recibida)
                    <div class="fw-semibold">{{ $transferencia->recibida_at->format('d/m/Y H:i') }}</div>
                    <div class="text-muted">{{ $transferencia->recibidaPor?->name }}</div>
                @else
                    <div class="fw-semibold">—</div>
                @endif
            </div>
            @if ($transferencia->transportista)
                <div class="col-md-3">
                    <div class="text-muted">Transportista</div>
                    <div class="fw-semibold">{{ $transferencia->transportista }}</div>
                </div>
            @endif
            @if ($transferencia->observaciones)
                <div class="col-md-9">
                    <div class="text-muted">Observaciones</div>
                    <div>{{ $transferencia->observaciones }}</div>
                </div>
            @endif
            @if ($transferencia->observaciones_recepcion)
                <div class="col-12">
                    <div class="text-muted">Observaciones de la recepción</div>
                    <div>{{ $transferencia->observaciones_recepcion }}</div>
                </div>
            @endif
        </div>
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Producto</th>
                    <th>Ubic. origen</th>
                    <th class="text-end">Enviado</th>
                    @if ($recibida)
                        <th class="text-end">Recibido</th>
                        <th class="text-end">Diferencia</th>
                        <th>Ubic. destino</th>
                    @else
                        <th class="solo-impresion text-end" style="width: 120px;">Recibido</th>
                    @endif
                </tr>
                </thead>
                <tbody>
                @foreach ($transferencia->items as $item)
                    @php($unidad = $item->producto?->unidad?->abreviatura)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $item->producto?->nombre }}</div>
                            <div class="small text-muted"><code>{{ $item->producto?->sku }}</code></div>
                            @if ($item->lote)
                                <div class="small">Lote {{ $item->lote->codigo }}
                                    @if ($item->lote->fecha_vencimiento)<span class="text-muted">· vence {{ $item->lote->fecha_vencimiento->format('d/m/Y') }}</span>@endif
                                </div>
                            @endif
                            @if (! empty($item->series))
                                <div class="small text-muted font-monospace">
                                    @foreach ($item->series as $numero)
                                        @php($falto = $recibida && ! in_array($numero, $item->series_recibidas ?? [], true))
                                        <span class="{{ $falto ? 'text-danger text-decoration-line-through' : '' }}">{{ $numero }}</span>@if (! $loop->last), @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="small">{{ $item->ubicacionOrigen?->codigo ?? '—' }}</td>
                        <td class="text-end fw-semibold">{{ Formato::cantidad($item->cantidad_enviada) }} {{ $unidad }}</td>
                        @if ($recibida)
                            <td class="text-end fw-semibold">{{ Formato::cantidad($item->cantidad_recibida) }} {{ $unidad }}</td>
                            <td class="text-end {{ $item->diferencia > 0 ? 'text-danger fw-semibold' : 'text-muted' }}">
                                {{ $item->diferencia > 0 ? '−' . Formato::cantidad($item->diferencia) : '—' }}
                            </td>
                            <td class="small">{{ $item->ubicacionDestino?->codigo ?? '—' }}</td>
                        @else
                            <td class="solo-impresion text-end">______</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @if ($transferencia->destino_diferencias)
            <div class="card-footer bg-white small">
                Lo que no llegó: <strong>{{ \App\Models\Transferencia::DIFERENCIAS[$transferencia->destino_diferencias] ?? $transferencia->destino_diferencias }}</strong>.
            </div>
        @endif
    </div>

    {{-- Firmas (solo al imprimir) --}}
    <div class="solo-impresion">
        <div class="row">
            <div class="col-4"><div class="firma">Entregó (origen)</div></div>
            <div class="col-4"><div class="firma">Transportó</div></div>
            <div class="col-4"><div class="firma">Recibió (destino)</div></div>
        </div>
    </div>

    {{-- Movimientos de stock generados --}}
    <div class="card no-imprimir">
        <div class="card-header bg-white fw-semibold">Movimientos de stock generados</div>
        <ul class="list-group list-group-flush">
            @foreach ($transferencia->movimientos as $mov)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>
                        <span class="badge text-bg-{{ $mov->color }}">{{ $mov->tipo_nombre }}</span>
                        N° {{ $mov->numero_texto }} · {{ $mov->observaciones }}
                        @if ($mov->motivo) · <span class="text-muted">{{ $mov->motivo->nombre }}</span>@endif
                        @if ($mov->estaAnulado())<span class="badge text-bg-dark">Anulado</span>@endif
                    </span>
                    <a href="{{ route('inventario.movimientos.show', $mov) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                </li>
            @endforeach
        </ul>
    </div>

    @if ($puedeAnular)
        <div class="modal fade" id="modalAnular" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('inventario.transferencias.anular', $transferencia) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5">Anular transferencia N° {{ $transferencia->numero_texto }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">La mercadería vuelve al stock de {{ $transferencia->origen?->nombre }}.</p>
                        <label class="form-label">Motivo *</label>
                        <input type="text" name="motivo" class="form-control" maxlength="255" required>
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
