{{-- resources/views/compras/proveedor.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', $proveedor->nombre)

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('compras.proveedores.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">{{ $proveedor->nombre }}</h1>
            <span class="text-muted small">{{ $proveedor->razon_social }} {{ $proveedor->cuit ? '· CUIT ' . $proveedor->cuit : '' }}</span>
        </div>
        <a href="{{ route('compras.ordenes.create', ['proveedor_id' => $proveedor->id]) }}" class="btn btn-stk"><i class="bi bi-receipt"></i> Nueva orden</a>
        @unless ($maestroExterno)
            <a href="{{ route('compras.proveedores.edit', $proveedor) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
            <form method="POST" action="{{ route('compras.proveedores.destroy', $proveedor) }}" onsubmit="return confirm('¿Eliminar el proveedor?');">
                @csrf @method('DELETE')
                <button class="btn btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        @endunless
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Datos</div>
                <ul class="list-group list-group-flush small">
                    @if ($proveedor->contacto)<li class="list-group-item"><i class="bi bi-person"></i> {{ $proveedor->contacto }}</li>@endif
                    @if ($proveedor->telefono)<li class="list-group-item"><i class="bi bi-telephone"></i> {{ $proveedor->telefono }}</li>@endif
                    @if ($proveedor->email)<li class="list-group-item"><i class="bi bi-envelope"></i> <a href="mailto:{{ $proveedor->email }}">{{ $proveedor->email }}</a></li>@endif
                    @if ($proveedor->domicilio)<li class="list-group-item"><i class="bi bi-geo-alt"></i> {{ collect([$proveedor->domicilio, $proveedor->localidad, $proveedor->provincia])->filter()->implode(', ') }}</li>@endif
                    @if ($proveedor->condiciones_pago)<li class="list-group-item"><i class="bi bi-cash"></i> {{ $proveedor->condiciones_pago }}</li>@endif
                    @if ($proveedor->plazo_entrega_dias !== null)<li class="list-group-item"><i class="bi bi-truck"></i> Entrega en {{ $proveedor->plazo_entrega_dias }} días</li>@endif
                </ul>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Últimas órdenes</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                        @forelse ($ordenes as $o)
                            <tr>
                                <td><a href="{{ route('compras.ordenes.show', $o) }}" class="text-decoration-none">N° {{ $o->numero_texto }}</a></td>
                                <td class="small">{{ $o->fecha->format('d/m/Y') }}</td>
                                <td class="small">{{ $o->deposito?->nombre }}</td>
                                <td><span class="badge text-bg-{{ $o->color }}">{{ $o->estado_nombre }}</span></td>
                                <td class="text-end">{{ Formato::moneda($o->total) }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-muted small p-3">Todavía no hay órdenes.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Productos que vende --}}
    <div class="card">
        <div class="card-header bg-white fw-semibold">Productos que le compramos</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Producto</th>
                    <th>Código del proveedor</th>
                    <th>Unidad de compra</th>
                    <th class="text-end">Último costo</th>
                    <th class="text-end">Mín. compra</th>
                    <th>Última compra</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($proveedor->productos as $v)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $v->producto?->nombre }}</div>
                            <div class="small text-muted"><code>{{ $v->producto?->sku }}</code>
                                @if ($v->es_principal)<span class="badge text-bg-primary">Proveedor principal</span>@endif
                            </div>
                        </td>
                        <td class="font-monospace small">{{ $v->codigo_proveedor }}</td>
                        <td class="small">{{ $v->presentacion?->nombre ?? $v->producto?->unidad?->nombre }}</td>
                        <td class="text-end">{{ $v->costo !== null ? Formato::moneda($v->costo) : '—' }}</td>
                        <td class="text-end small">{{ $v->minimo_compra ? Formato::cantidad($v->minimo_compra) : '—' }}</td>
                        <td class="small">{{ $v->ultima_compra_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('compras.proveedores.productos.destroy', [$proveedor, $v]) }}" onsubmit="return confirm('¿Desvincular el producto?');">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Desvincular"><i class="bi bi-x-lg"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted small p-3">Todavía no hay productos asociados. Se asocian solos con la primera recepción, o cargalos acá.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card-footer bg-white">
            <form method="POST" action="{{ route('compras.proveedores.productos.store', $proveedor) }}" class="row g-2 align-items-end" id="formVinculo">
                @csrf
                <div class="col-lg-4 position-relative">
                    <label class="form-label small mb-1">Producto *</label>
                    <input type="hidden" name="producto_id" id="vincProducto" value="{{ old('producto_id') }}">
                    <input type="text" id="vincBuscar" class="form-control form-control-sm" placeholder="Buscá por nombre, SKU o código" autocomplete="off">
                    <div class="list-group position-absolute w-100 shadow d-none" id="vincResultados" style="z-index: 1050; max-height: 260px; overflow:auto;"></div>
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Código del proveedor</label>
                    <input type="text" name="codigo_proveedor" class="form-control form-control-sm" maxlength="60">
                </div>
                <div class="col-6 col-lg-2">
                    <label class="form-label small mb-1">Unidad de compra</label>
                    <select name="presentacion_id" id="vincPresentacion" class="form-select form-select-sm"><option value="">Unidad</option></select>
                </div>
                <div class="col-4 col-lg-1">
                    <label class="form-label small mb-1">Costo un.</label>
                    <input type="number" name="costo" class="form-control form-control-sm" step="any" min="0">
                </div>
                <div class="col-4 col-lg-1">
                    <label class="form-label small mb-1">Mín.</label>
                    <input type="number" name="minimo_compra" class="form-control form-control-sm" step="any" min="0">
                </div>
                <div class="col-4 col-lg-2 d-flex gap-2 align-items-end">
                    <div class="form-check small mb-1">
                        <input class="form-check-input" type="checkbox" name="es_principal" value="1" id="vincPrincipal">
                        <label class="form-check-label" for="vincPrincipal">Principal</label>
                    </div>
                    <button class="btn btn-sm btn-stk ms-auto"><i class="bi bi-plus-lg"></i></button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
(() => {
    const URL_BUSCAR = @json(route('compras.productos.buscar'));
    const PROVEEDOR = @json($proveedor->id);
    const buscador = document.getElementById('vincBuscar');
    const lista = document.getElementById('vincResultados');
    const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let temporizador = null;
    let resultados = [];

    function elegir(p) {
        document.getElementById('vincProducto').value = p.id;
        buscador.value = `${p.nombre} (${p.sku})`;
        document.getElementById('vincPresentacion').innerHTML = '<option value="">Unidad</option>'
            + p.presentaciones.map((pr) => `<option value="${pr.id}">${escapar(pr.nombre)} (${pr.cantidad})</option>`).join('');
        lista.classList.add('d-none');
    }

    buscador.addEventListener('input', () => {
        clearTimeout(temporizador);
        document.getElementById('vincProducto').value = '';
        const texto = buscador.value.trim();
        if (texto.length < 2) { lista.classList.add('d-none'); return; }

        temporizador = setTimeout(async () => {
            const url = new URL(URL_BUSCAR, window.location.origin);
            url.searchParams.set('q', texto);
            url.searchParams.set('proveedor_id', PROVEEDOR);
            const datos = await (await fetch(url, { headers: { 'Accept': 'application/json' } })).json();
            resultados = datos.resultados;
            lista.innerHTML = resultados.length
                ? resultados.map((p, i) => `<button type="button" class="list-group-item list-group-item-action small" data-i="${i}">${escapar(p.nombre)} <code>${escapar(p.sku)}</code></button>`).join('')
                : '<div class="list-group-item small text-muted">Sin resultados</div>';
            lista.classList.remove('d-none');
            if (datos.exacto && resultados.length === 1) elegir(resultados[0]);
        }, 250);
    });

    lista.addEventListener('click', (e) => {
        const boton = e.target.closest('[data-i]');
        if (boton) elegir(resultados[parseInt(boton.dataset.i, 10)]);
    });

    document.getElementById('formVinculo').addEventListener('submit', (e) => {
        if (!document.getElementById('vincProducto').value) {
            e.preventDefault();
            alert('Elegí un producto de la lista.');
        }
    });
})();
</script>
@endpush
