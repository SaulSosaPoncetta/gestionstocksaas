{{-- resources/views/compras/orden.blade.php (detalle + orden de compra imprimible) --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Orden de compra N° ' . $orden->numero_texto)

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
</style>
@endpush

@section('contenido')
    @php
        $usuario = auth()->user();
        $puedeEditar = $usuario->tieneRol('admin', 'encargado');
        $hayRecepciones = $orden->recepciones->isNotEmpty();
        $lineasMail = $orden->items->map(fn ($i) => '- ' . Formato::cantidad($i->presentacion_id ? $i->cantidad_presentacion : $i->cantidad) . ' ' . ($i->presentacion?->nombre ?? $i->producto?->unidad?->abreviatura) . ' ' . $i->producto?->nombre)->implode("\n");
        $mailto = 'mailto:' . ($orden->proveedor?->email ?? '') . '?subject=' . rawurlencode("Orden de compra N° {$orden->numero_texto} - {$empresa->nombre}")
            . '&body=' . rawurlencode("Hola, les enviamos la orden de compra N° {$orden->numero_texto}:\n\n{$lineasMail}\n\nEntregar en: {$orden->deposito?->nombre} {$orden->deposito?->direccion}\n" . ($orden->fecha_entrega ? 'Fecha de entrega: ' . $orden->fecha_entrega->format('d/m/Y') . "\n" : '') . "\nGracias.\n{$empresa->nombre}");
    @endphp

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap no-imprimir">
        <a href="{{ route('compras.ordenes.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">Orden de compra N° {{ $orden->numero_texto }}
                <span class="badge text-bg-{{ $orden->color }} {{ $orden->color === 'light' ? 'border' : '' }} align-middle">{{ $orden->estado_nombre }}</span>
            </h1>
            <span class="text-muted small">{{ $orden->proveedor?->nombre }} · recibe en {{ $orden->deposito?->nombre }}</span>
        </div>

        <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i> Imprimir / PDF</button>
        @if ($puedeEditar && $orden->proveedor?->email)
            <a href="{{ $mailto }}" class="btn btn-light"><i class="bi bi-envelope"></i> Enviar por mail</a>
        @endif
        @if ($puedeEditar && $orden->estado === 'borrador')
            <a href="{{ route('compras.ordenes.edit', $orden) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
            <form method="POST" action="{{ route('compras.ordenes.enviar', $orden) }}">
                @csrf
                <button class="btn btn-primary"><i class="bi bi-send"></i> Marcar como enviada</button>
            </form>
        @elseif ($puedeEditar && $orden->estado === 'enviada' && ! $hayRecepciones)
            <a href="{{ route('compras.ordenes.edit', $orden) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
        @endif
        @if ($orden->estaAbierta() && $orden->avance < 100)
            <a href="{{ route('compras.recepciones.create', $orden) }}" class="btn btn-success"><i class="bi bi-box-arrow-in-down"></i> Recibir mercadería</a>
        @endif
        @if ($puedeEditar && in_array($orden->estado, ['borrador', 'enviada'], true) && ! $hayRecepciones)
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalCancelar"><i class="bi bi-x-octagon"></i> Cancelar</button>
        @endif
        @if ($puedeEditar && $orden->estado === 'parcial')
            <button type="button" class="btn btn-outline-dark" data-bs-toggle="modal" data-bs-target="#modalCerrar"><i class="bi bi-lock"></i> Cerrar con faltantes</button>
        @endif
    </div>

    {{-- Encabezado impreso --}}
    <div class="solo-impresion mb-3">
        <div class="d-flex justify-content-between">
            <div>
                <div class="h5 mb-0">{{ $empresa->razon_social }}</div>
                <div class="small">CUIT {{ $empresa->cuit }}{{ $empresa->domicilio ? ' · ' . $empresa->domicilio : '' }}</div>
                <div class="small">{{ $empresa->telefono }} {{ $empresa->email }}</div>
            </div>
            <div class="text-end">
                <div class="h5 mb-0">ORDEN DE COMPRA</div>
                <div>N° {{ $orden->numero_texto }}</div>
                <div class="small">Fecha {{ $orden->fecha->format('d/m/Y') }}</div>
            </div>
        </div>
        <hr>
    </div>

    <div class="card mb-3">
        <div class="card-body row g-3 small">
            <div class="col-md-4">
                <div class="text-muted">Proveedor</div>
                <div class="fw-semibold">{{ $orden->proveedor?->razon_social }}</div>
                <div>{{ $orden->proveedor?->cuit ? 'CUIT ' . $orden->proveedor->cuit : '' }}</div>
                <div class="text-muted">{{ $orden->proveedor?->email }} {{ $orden->proveedor?->telefono }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted">Entregar en</div>
                <div class="fw-semibold">{{ $orden->deposito?->nombre }}</div>
                <div>{{ collect([$orden->deposito?->direccion, $orden->deposito?->localidad])->filter()->implode(', ') }}</div>
            </div>
            <div class="col-md-4">
                <div class="text-muted">Entrega estimada / pago</div>
                <div class="fw-semibold">{{ $orden->fecha_entrega?->format('d/m/Y') ?? 'A convenir' }}</div>
                <div>{{ $orden->condiciones_pago }}</div>
            </div>
            @if ($orden->observaciones)
                <div class="col-12">
                    <div class="text-muted">Observaciones</div>
                    <div>{{ $orden->observaciones }}</div>
                </div>
            @endif
            @if ($orden->motivo_cierre)
                <div class="col-12 no-imprimir">
                    <div class="text-muted">Motivo de {{ $orden->estado === 'cancelada' ? 'cancelación' : 'cierre' }}</div>
                    <div>{{ $orden->motivo_cierre }}</div>
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
                    <th class="text-end">Cantidad</th>
                    <th class="text-end">Costo unit.</th>
                    <th class="text-end">IVA</th>
                    <th class="text-end">Subtotal</th>
                    <th class="text-end no-imprimir">Recibido</th>
                    <th class="text-end no-imprimir">Pendiente</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($orden->items as $item)
                    @php($unidad = $item->producto?->unidad?->abreviatura)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $item->producto?->nombre }}</div>
                            <div class="small text-muted">
                                <code>{{ $item->producto?->sku }}</code>
                                @if ($codigos[$item->producto_id] ?? null) · Cód. prov.: <code>{{ $codigos[$item->producto_id] }}</code>@endif
                            </div>
                        </td>
                        <td class="text-end text-nowrap">
                            @if ($item->presentacion)
                                {{ Formato::cantidad($item->cantidad_presentacion) }} × {{ $item->presentacion->nombre }}
                                <div class="small text-muted">{{ Formato::cantidad($item->cantidad) }} {{ $unidad }}</div>
                            @else
                                {{ Formato::cantidad($item->cantidad) }} {{ $unidad }}
                            @endif
                        </td>
                        <td class="text-end">{{ Formato::moneda($item->costo_unitario) }}</td>
                        <td class="text-end small">{{ rtrim(rtrim(number_format((float) $item->alicuota_iva, 2, ',', ''), '0'), ',') }}%</td>
                        <td class="text-end">{{ Formato::moneda($item->subtotal) }}</td>
                        <td class="text-end no-imprimir">{{ Formato::cantidad($item->cantidad_recibida) }}</td>
                        <td class="text-end no-imprimir {{ $item->pendiente > 0 ? 'fw-semibold text-warning-emphasis' : 'text-muted' }}">{{ Formato::cantidad($item->pendiente) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot class="table-light">
                <tr><th colspan="4" class="text-end">Subtotal</th><th class="text-end">{{ Formato::moneda($orden->subtotal) }}</th><th colspan="2" class="no-imprimir"></th></tr>
                <tr><th colspan="4" class="text-end">IVA</th><th class="text-end">{{ Formato::moneda($orden->iva) }}</th><th colspan="2" class="no-imprimir"></th></tr>
                <tr><th colspan="4" class="text-end">Total</th><th class="text-end fs-5">{{ Formato::moneda($orden->total) }}</th><th colspan="2" class="no-imprimir"></th></tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- Recepciones --}}
    <div class="card no-imprimir">
        <div class="card-header bg-white fw-semibold">Recepciones</div>
        <ul class="list-group list-group-flush">
            @forelse ($orden->recepciones as $r)
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span>
                        N° {{ $r->numero_texto }} · {{ $r->fecha->format('d/m/Y') }}
                        @if ($r->remito) · Remito {{ $r->remito }} @endif
                        · {{ $r->usuario?->name }}
                        @if ($r->estaAnulada())<span class="badge text-bg-dark">Anulada</span>@endif
                    </span>
                    <a href="{{ route('compras.recepciones.show', $r) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                </li>
            @empty
                <li class="list-group-item text-muted small">Todavía no llegó mercadería de esta orden.</li>
            @endforelse
        </ul>
    </div>

    @foreach (['Cancelar' => 'cancelar', 'Cerrar' => 'cerrar'] as $titulo => $accion)
        <div class="modal fade" id="modal{{ $titulo }}" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route("compras.ordenes.{$accion}", $orden) }}" class="modal-content">
                    @csrf
                    <div class="modal-header">
                        <h2 class="modal-title h5">{{ $titulo }} la orden N° {{ $orden->numero_texto }}</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted">
                            {{ $accion === 'cerrar' ? 'Lo que quedó pendiente ya no se va a esperar ni se sugiere en la reposición como "pedido".' : 'La orden queda cancelada y no se puede recibir.' }}
                        </p>
                        <label class="form-label">Motivo</label>
                        <input type="text" name="motivo" class="form-control" maxlength="255">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                        <button class="btn {{ $accion === 'cerrar' ? 'btn-dark' : 'btn-danger' }}">{{ $titulo }}</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach
@endsection
