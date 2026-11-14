{{-- resources/views/compras/recepcion-form.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Recibir orden N° ' . $orden->numero_texto)

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('compras.ordenes.show', $orden) }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-box-arrow-in-down"></i> Recibir mercadería · OC N° {{ $orden->numero_texto }}</h1>
            <span class="text-muted small">{{ $orden->proveedor?->nombre }} → {{ $orden->deposito?->nombre }}</span>
        </div>
    </div>

    <form method="POST" action="{{ route('compras.recepciones.store', $orden) }}" id="formRecepcion">
        @csrf

        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <label class="form-label">Remito del proveedor</label>
                    <input type="text" name="remito" class="form-control" maxlength="60" value="{{ old('remito') }}" placeholder="0001-00001234">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Factura</label>
                    <input type="text" name="factura" class="form-control" maxlength="60" value="{{ old('factura') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Fecha *</label>
                    <input type="date" name="fecha" class="form-control" required max="{{ today()->toDateString() }}" value="{{ old('fecha', today()->toDateString()) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones') }}">
                </div>
                <div class="col-12">
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="actualizar_costos" id="actCostos" value="1" @checked($errors->any() ? old('actualizar_costos') : true)>
                        <label class="form-check-label" for="actCostos">Actualizar el costo de reposición de los productos</label>
                    </div>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" name="actualizar_precios" id="actPrecios" value="1" @checked(old('actualizar_precios'))>
                        <label class="form-check-label" for="actPrecios">Recalcular el precio de venta con el margen de cada producto</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">¿Qué llegó?</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="llegoTodo"><i class="bi bi-check2-all"></i> Llegó todo lo pendiente</button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Producto</th>
                        <th class="text-end" style="width: 110px;">Pendiente</th>
                        <th style="width: 150px;">Llegó</th>
                        <th style="width: 160px;">Costo unit. (sin IVA)</th>
                        @if ($ubicaciones->isNotEmpty())<th style="width: 180px;">Ubicar en</th>@endif
                        <th style="width: 260px;">Lote / series</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($orden->items as $item)
                        @continue($item->pendiente <= 0)
                        @php
                            $p = $item->producto;
                            $unidad = $p?->unidad?->abreviatura;
                            $base = "lineas.{$item->id}";
                        @endphp
                        <tr class="fila" data-pendiente="{{ $item->pendiente }}">
                            <td>
                                <div class="fw-semibold">{{ $p?->nombre }}</div>
                                <div class="small text-muted"><code>{{ $p?->sku }}</code>
                                    @if ($item->presentacion) · pedido: {{ Formato::cantidad($item->cantidad_presentacion) }} × {{ $item->presentacion->nombre }}@endif
                                </div>
                            </td>
                            <td class="text-end">{{ Formato::cantidad($item->pendiente) }} {{ $unidad }}</td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="lineas[{{ $item->id }}][cantidad]" class="form-control cantidad" min="0" max="{{ $item->pendiente }}"
                                           step="{{ $p?->unidad?->permite_decimales && ! $p?->usa_series ? 'any' : '1' }}"
                                           value="{{ old("{$base}.cantidad") }}" @if ($p?->usa_series) readonly tabindex="-1" @endif>
                                    <span class="input-group-text">{{ $unidad }}</span>
                                </div>
                            </td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text">$</span>
                                    <input type="number" name="lineas[{{ $item->id }}][costo_unitario]" class="form-control" min="0" step="any"
                                           value="{{ old("{$base}.costo_unitario", (float) $item->costo_unitario) }}">
                                </div>
                            </td>
                            @if ($ubicaciones->isNotEmpty())
                                <td>
                                    <select name="lineas[{{ $item->id }}][ubicacion_id]" class="form-select form-select-sm">
                                        <option value="">Sin ubicar</option>
                                        @foreach ($ubicaciones as $u)
                                            <option value="{{ $u->id }}" @selected((string) old("{$base}.ubicacion_id") === (string) $u->id)>{{ $u->etiqueta }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            @endif
                            <td>
                                @if ($p?->usa_lotes)
                                    <div class="input-group input-group-sm">
                                        <input type="text" name="lineas[{{ $item->id }}][lote_codigo]" class="form-control text-uppercase lote" maxlength="60"
                                               placeholder="N° de lote" list="lotes{{ $item->id }}" value="{{ old("{$base}.lote_codigo") }}">
                                        <input type="date" name="lineas[{{ $item->id }}][lote_vencimiento]" class="form-control" title="Vencimiento" value="{{ old("{$base}.lote_vencimiento") }}">
                                    </div>
                                    <datalist id="lotes{{ $item->id }}">
                                        @foreach ($lotesConocidos[$item->producto_id] ?? [] as $l)
                                            <option value="{{ $l['codigo'] }}">{{ $l['vencimiento'] ? 'vence ' . \Illuminate\Support\Carbon::parse($l['vencimiento'])->format('d/m/Y') : '' }}</option>
                                        @endforeach
                                    </datalist>
                                @endif
                                @if ($p?->usa_series)
                                    <textarea name="lineas[{{ $item->id }}][series]" class="form-control form-control-sm font-monospace series {{ $p?->usa_lotes ? 'mt-1' : '' }}" rows="2"
                                              placeholder="Números de serie (uno por línea)">{{ old("{$base}.series") }}</textarea>
                                @endif
                                @if (! $p?->usa_lotes && ! $p?->usa_series)
                                    <span class="text-muted small">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white small text-muted">
                Dejá vacío (o en 0) lo que no llegó: queda pendiente en la orden para una próxima entrega.
            </div>
        </div>

        <button class="btn btn-success" id="btnRecibir"><i class="bi bi-check-lg"></i> Confirmar recepción e ingresar al stock</button>
    </form>
@endsection

@push('scripts')
<script>
(() => {
    const filas = document.querySelectorAll('tr.fila');

    // Con series, la cantidad es la cantidad de números cargados
    document.querySelectorAll('textarea.series').forEach((area) => area.addEventListener('input', () => {
        const numeros = [...new Set(area.value.split(/[\r\n,;]+/).map((n) => n.trim()).filter(Boolean))];
        area.closest('tr').querySelector('.cantidad').value = numeros.length || '';
    }));

    document.getElementById('llegoTodo').addEventListener('click', () => {
        filas.forEach((fila) => {
            const campo = fila.querySelector('.cantidad');
            if (!campo.readOnly) campo.value = fila.dataset.pendiente;
        });
    });

    document.getElementById('formRecepcion').addEventListener('submit', (e) => {
        const algo = [...filas].some((f) => parseFloat(f.querySelector('.cantidad').value) > 0);
        if (!algo) { e.preventDefault(); alert('Indicá la cantidad que llegó de al menos un producto.'); return; }

        // Los productos con lote que llegaron necesitan el lote
        const sinLote = [...filas].find((f) => parseFloat(f.querySelector('.cantidad').value) > 0 && f.querySelector('.lote') && !f.querySelector('.lote').value.trim());
        if (sinLote) { e.preventDefault(); sinLote.querySelector('.lote').focus(); alert('Indicá el lote de los productos que lo requieren.'); return; }

        document.getElementById('btnRecibir').disabled = true;
    });

    filas[0]?.querySelector('.cantidad:not([readonly]), textarea.series')?.focus();
})();
</script>
@endpush
