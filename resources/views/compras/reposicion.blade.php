{{-- resources/views/compras/reposicion.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Sugerencia de reposición')

@section('contenido')
    <div class="mb-3">
        <h1 class="h4 mb-0">Sugerencia de reposición</h1>
        <span class="text-muted small">
            Productos que llegaron al punto de reposición o están bajo el mínimo, descontando lo que ya está pedido.
            Se sugiere reponer hasta el stock máximo (o el doble del punto de reposición si no tiene máximo).
        </span>
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small mb-1">Depósito a reponer</label>
                <select name="deposito_id" class="form-select" onchange="this.form.submit()">
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->etiqueta }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label small mb-1">Proveedor</label>
                <select name="proveedor_id" class="form-select" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    @foreach ($proveedores as $p)
                        <option value="{{ $p->id }}" @selected($filtros['proveedor_id'] === $p->id)>{{ $p->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
            </div>
        </div>
    </form>

    @if ($sugerencias->isEmpty())
        <div class="card card-body text-center text-muted py-5">
            <i class="bi bi-emoji-smile fs-1 d-block mb-2"></i>
            No hay nada para reponer en este depósito. Se calcula con el stock mínimo, el punto de reposición y el máximo de cada producto.
        </div>
    @else
        <form method="POST" action="{{ route('compras.reposicion.generar') }}">
            @csrf
            <input type="hidden" name="deposito_id" value="{{ $filtros['deposito_id'] }}">

            <div class="card">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th style="width: 40px;"><input class="form-check-input" type="checkbox" id="todos" checked></th>
                            <th>Producto</th>
                            <th class="text-end">Stock</th>
                            <th class="text-end">Pedido</th>
                            <th class="text-end">Mín. / Repos. / Máx.</th>
                            <th style="width: 220px;">Proveedor</th>
                            <th style="width: 140px;">A pedir</th>
                            <th class="text-end">Costo est.</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($sugerencias as $i => $s)
                            @php($unidad = $s->producto->unidad?->abreviatura)
                            <tr class="fila" data-costo="{{ $s->costo }}">
                                <td>
                                    <input type="hidden" name="seleccion[{{ $i }}][producto_id]" value="{{ $s->producto->id }}">
                                    <input class="form-check-input incluir" type="checkbox" name="seleccion[{{ $i }}][incluir]" value="1" @checked($s->proveedor_id)>
                                </td>
                                <td>
                                    <div class="fw-semibold">{{ $s->producto->nombre }}
                                        @if ($s->urgente)<span class="badge text-bg-danger">Bajo el mínimo</span>@endif
                                    </div>
                                    <div class="small text-muted"><code>{{ $s->producto->sku }}</code>
                                        @if ($s->vinculo?->presentacion) · se compra por {{ $s->vinculo->presentacion->nombre }}@endif
                                    </div>
                                </td>
                                <td class="text-end fw-semibold {{ $s->urgente ? 'text-danger' : '' }}">{{ Formato::cantidad($s->stock) }} {{ $unidad }}</td>
                                <td class="text-end small">{{ $s->pendiente ? Formato::cantidad($s->pendiente) : '—' }}</td>
                                <td class="text-end small text-muted">
                                    {{ Formato::cantidad($s->minimo) }} / {{ $s->reposicion !== null ? Formato::cantidad($s->reposicion) : '—' }} / {{ $s->maximo !== null ? Formato::cantidad($s->maximo) : '—' }}
                                </td>
                                <td>
                                    <select name="seleccion[{{ $i }}][proveedor_id]" class="form-select form-select-sm proveedor">
                                        <option value="">Elegí…</option>
                                        @foreach ($s->producto->proveedores as $v)
                                            <option value="{{ $v->proveedor_id }}" @selected($v->proveedor_id === $s->proveedor_id)>{{ $v->proveedor?->nombre }}{{ $v->es_principal ? ' ★' : '' }}</option>
                                        @endforeach
                                        @if ($s->producto->proveedores->isNotEmpty())<option disabled>──────────</option>@endif
                                        @foreach ($proveedores->whereNotIn('id', $s->producto->proveedores->pluck('proveedor_id')) as $p)
                                            <option value="{{ $p->id }}">{{ $p->nombre }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <div class="input-group input-group-sm">
                                        <input type="number" name="seleccion[{{ $i }}][cantidad]" class="form-control cantidad" min="0" step="any" value="{{ $s->sugerido }}">
                                        <span class="input-group-text">{{ $unidad }}</span>
                                    </div>
                                </td>
                                <td class="text-end small costo">{{ Formato::moneda($s->sugerido * $s->costo) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        <tfoot class="table-light">
                        <tr><th colspan="7" class="text-end">Total estimado (sin IVA) de lo seleccionado</th><th class="text-end" id="total">$ 0,00</th></tr>
                        </tfoot>
                    </table>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="small text-muted">Se arma una orden en borrador por cada proveedor. Después las revisás y las enviás.</span>
                    <button class="btn btn-stk"><i class="bi bi-receipt"></i> Generar órdenes de compra</button>
                </div>
            </div>
        </form>
    @endif
@endsection

@push('scripts')
<script>
(() => {
    const moneda = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
    const filas = document.querySelectorAll('tr.fila');

    function recalcular() {
        let total = 0;
        filas.forEach((f) => {
            const subtotal = (parseFloat(f.querySelector('.cantidad').value) || 0) * (parseFloat(f.dataset.costo) || 0);
            f.querySelector('.costo').textContent = moneda.format(subtotal);
            if (f.querySelector('.incluir').checked) total += subtotal;
        });
        const celda = document.getElementById('total');
        if (celda) celda.textContent = moneda.format(total);
    }

    document.getElementById('todos')?.addEventListener('change', (e) => {
        filas.forEach((f) => { f.querySelector('.incluir').checked = e.target.checked; });
        recalcular();
    });

    // Elegir proveedor marca la fila
    filas.forEach((f) => {
        f.querySelector('.proveedor').addEventListener('change', (e) => { if (e.target.value) f.querySelector('.incluir').checked = true; recalcular(); });
        f.querySelector('.cantidad').addEventListener('input', recalcular);
        f.querySelector('.incluir').addEventListener('change', recalcular);
    });

    recalcular();
})();
</script>
@endpush
