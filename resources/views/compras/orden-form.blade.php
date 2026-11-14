{{-- resources/views/compras/orden-form.blade.php --}}
@extends('layouts.app')

@section('titulo', $orden->exists ? 'Editar orden N° ' . $orden->numero_texto : 'Nueva orden de compra')

@push('styles')
<style>
    .buscador { position: relative; }
    .buscador .resultados { position: absolute; z-index: 1050; left: 0; right: 0; top: 100%; max-height: 320px; overflow-y: auto; }
    .buscador .resultados .list-group-item { cursor: pointer; }
    .buscador .resultados .list-group-item.activo { background: #ccfbf1; }
</style>
@endpush

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ $orden->exists ? route('compras.ordenes.show', $orden) : route('compras.ordenes.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <h1 class="h4 mb-0"><i class="bi bi-receipt"></i> {{ $orden->exists ? 'Editar orden N° ' . $orden->numero_texto : 'Nueva orden de compra' }}</h1>
    </div>

    <form method="POST" action="{{ $orden->exists ? route('compras.ordenes.update', $orden) : route('compras.ordenes.store') }}" id="formOrden">
        @csrf
        @if ($orden->exists) @method('PUT') @endif

        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Proveedor *</label>
                    <select name="proveedor_id" id="proveedor" class="form-select" required>
                        <option value="">Elegí…</option>
                        @foreach ($proveedores as $p)
                            <option value="{{ $p->id }}" data-pago="{{ $p->condiciones_pago }}" data-plazo="{{ $p->plazo_entrega_dias }}"
                                    @selected((string) old('proveedor_id', $orden->proveedor_id) === (string) $p->id)>{{ $p->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Recibe en *</label>
                    <select name="deposito_id" class="form-select" required>
                        @foreach ($depositos as $d)
                            <option value="{{ $d->id }}" @selected($depositoInicial === $d->id)>{{ $d->etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Fecha *</label>
                    <input type="date" name="fecha" id="fecha" class="form-control" required value="{{ old('fecha', $orden->fecha?->toDateString()) }}">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Entrega estimada</label>
                    <input type="date" name="fecha_entrega" id="entrega" class="form-control" value="{{ old('fecha_entrega', $orden->fecha_entrega?->toDateString()) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Condiciones de pago</label>
                    <input type="text" name="condiciones_pago" id="pago" class="form-control" maxlength="150" value="{{ old('condiciones_pago', $orden->condiciones_pago) }}">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Observaciones para el proveedor</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones', $orden->observaciones) }}">
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white">
                <div class="buscador">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                        <input type="text" id="buscador" class="form-control form-control-lg" autocomplete="off"
                               placeholder="Producto: código de barras, SKU, nombre o código del proveedor">
                    </div>
                    <div class="resultados list-group shadow d-none" id="resultados"></div>
                </div>
                <div class="small text-muted mt-1">Los costos son sin IVA. Si el producto ya se le compró a este proveedor, se propone su último costo y su unidad de compra.</div>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Producto</th>
                        <th style="width: 190px;">Unidad de compra</th>
                        <th style="width: 130px;">Cantidad</th>
                        <th style="width: 170px;">Costo (sin IVA)</th>
                        <th class="text-end" style="width: 140px;">Subtotal</th>
                        <th style="width: 50px;"></th>
                    </tr>
                    </thead>
                    <tbody id="lineas">
                    <tr id="filaVacia"><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-upc-scan fs-1 d-block mb-2"></i>Todavía no agregaste productos.</td></tr>
                    </tbody>
                    <tfoot class="table-light">
                    <tr><th colspan="4" class="text-end">Subtotal</th><th class="text-end" id="subtotal">$ 0,00</th><th></th></tr>
                    <tr><th colspan="4" class="text-end">IVA</th><th class="text-end" id="iva">$ 0,00</th><th></th></tr>
                    <tr><th colspan="4" class="text-end">Total</th><th class="text-end fs-5" id="total">$ 0,00</th><th></th></tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-footer bg-white text-end">
                <button class="btn btn-stk" id="btnGuardar"><i class="bi bi-check-lg"></i> Guardar en borrador</button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
(() => {
    const URL_BUSCAR = @json(route('compras.productos.buscar'));
    const PREVIAS = @json($lineasPrevias);

    const tbody = document.getElementById('lineas');
    const filaVacia = document.getElementById('filaVacia');
    const buscador = document.getElementById('buscador');
    const resultados = document.getElementById('resultados');
    const selectProveedor = document.getElementById('proveedor');

    const moneda = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
    const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let indice = 0;
    let opciones = [];
    let activo = -1;

    // Al elegir proveedor se proponen sus condiciones y el plazo de entrega
    selectProveedor.addEventListener('change', () => {
        const op = selectProveedor.selectedOptions[0];
        if (op?.dataset.pago && !document.getElementById('pago').value) document.getElementById('pago').value = op.dataset.pago;
        if (op?.dataset.plazo && !document.getElementById('entrega').value) {
            const fecha = new Date(document.getElementById('fecha').value || Date.now());
            fecha.setDate(fecha.getDate() + parseInt(op.dataset.plazo, 10));
            document.getElementById('entrega').value = fecha.toISOString().slice(0, 10);
        }
    });

    function factor(fila) {
        const sel = fila.querySelector('.presentacion');
        return parseFloat(sel.selectedOptions[0]?.dataset.cantidad || '1');
    }

    function agregarLinea(p, valores = {}) {
        const existente = tbody.querySelector(`tr.linea[data-producto="${p.id}"]`);
        if (existente && valores.cantidad === undefined) {
            const campo = existente.querySelector('.cantidad');
            campo.value = (parseFloat(campo.value) || 0) + 1;
            campo.focus(); campo.select();
            recalcular();
            return;
        }

        const i = indice++;
        const presentacion = valores.presentacion_id !== undefined ? valores.presentacion_id : (p.presentacion_sugerida ?? '');
        const opcionesPres = `<option value="" data-cantidad="1">${escapar(p.unidad ?? 'Unidad')}</option>`
            + p.presentaciones.map((pr) => `<option value="${pr.id}" data-cantidad="${pr.cantidad}" ${String(pr.id) === String(presentacion) ? 'selected' : ''}>${escapar(pr.nombre)} (${pr.cantidad} ${escapar(p.unidad)})</option>`).join('');

        const fila = document.createElement('tr');
        fila.className = 'linea';
        fila.dataset.producto = p.id;
        fila.dataset.costoUnidad = p.costo;
        fila.dataset.iva = p.iva;
        fila.innerHTML = `
            <td>
                <input type="hidden" name="lineas[${i}][producto_id]" value="${p.id}">
                <div class="fw-semibold">${escapar(p.nombre)}</div>
                <div class="small text-muted"><code>${escapar(p.sku)}</code>${p.codigo_proveedor ? ` · Cód. prov.: <code>${escapar(p.codigo_proveedor)}</code>` : ''} · IVA ${p.iva}%</div>
            </td>
            <td><select name="lineas[${i}][presentacion_id]" class="form-select form-select-sm presentacion">${opcionesPres}</select></td>
            <td><input type="number" name="lineas[${i}][cantidad]" class="form-control form-control-sm cantidad" min="0" step="${p.permite_decimales ? 'any' : '1'}" required value="${escapar(valores.cantidad ?? 1)}"></td>
            <td>
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" name="lineas[${i}][costo_unitario]" class="form-control costo" min="0" step="any" required value="">
                </div>
                <div class="small text-muted por-unidad"></div>
            </td>
            <td class="text-end subtotal">$ 0,00</td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x-lg"></i></button></td>`;

        tbody.appendChild(fila);

        const costo = fila.querySelector('.costo');
        costo.value = valores.costo_unitario !== undefined && valores.costo_unitario !== ''
            ? valores.costo_unitario
            : Math.round(p.costo * factor(fila) * 10000) / 10000;

        recalcular();
        if (valores.cantidad === undefined) { const c = fila.querySelector('.cantidad'); c.focus(); c.select(); }
    }

    function recalcular() {
        let subtotal = 0, iva = 0;

        tbody.querySelectorAll('tr.linea').forEach((fila) => {
            const cantidad = parseFloat(fila.querySelector('.cantidad').value) || 0;
            const costo = parseFloat(fila.querySelector('.costo').value) || 0;
            const linea = cantidad * costo;
            const f = factor(fila);
            subtotal += linea;
            iva += linea * (parseFloat(fila.dataset.iva) || 0) / 100;
            fila.querySelector('.subtotal').textContent = moneda.format(linea);
            fila.querySelector('.por-unidad').textContent = f > 1 ? `${moneda.format(costo / f)} por unidad` : '';
        });

        document.getElementById('subtotal').textContent = moneda.format(subtotal);
        document.getElementById('iva').textContent = moneda.format(iva);
        document.getElementById('total').textContent = moneda.format(subtotal + iva);
        filaVacia.classList.toggle('d-none', !!tbody.querySelector('tr.linea'));
    }

    // Cambiar la unidad de compra recalcula el costo propuesto
    tbody.addEventListener('change', (e) => {
        if (!e.target.matches('.presentacion')) return;
        const fila = e.target.closest('tr');
        fila.querySelector('.costo').value = Math.round(parseFloat(fila.dataset.costoUnidad) * factor(fila) * 10000) / 10000;
        recalcular();
    });
    tbody.addEventListener('input', (e) => { if (e.target.matches('.cantidad, .costo')) recalcular(); });
    tbody.addEventListener('click', (e) => {
        const b = e.target.closest('.quitar');
        if (b) { b.closest('tr').remove(); recalcular(); buscador.focus(); }
    });
    tbody.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); buscador.focus(); }
    });

    /* ---------- Buscador ---------- */
    async function buscar(texto) {
        const url = new URL(URL_BUSCAR, window.location.origin);
        url.searchParams.set('q', texto);
        if (selectProveedor.value) url.searchParams.set('proveedor_id', selectProveedor.value);
        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) throw new Error('No se pudo buscar el producto.');
        return r.json();
    }

    function mostrar(lista) {
        opciones = lista; activo = lista.length ? 0 : -1;
        resultados.innerHTML = lista.length
            ? lista.map((p, i) => `<div class="list-group-item ${i === 0 ? 'activo' : ''}" data-i="${i}"><span class="fw-semibold">${escapar(p.nombre)}</span> <code class="small">${escapar(p.sku)}</code>${p.codigo_proveedor ? ` <span class="small text-muted">· ${escapar(p.codigo_proveedor)}</span>` : ''}</div>`).join('')
            : '<div class="list-group-item text-muted">Sin resultados (solo productos que controlan stock).</div>';
        resultados.classList.remove('d-none');
    }

    function cerrar() { resultados.classList.add('d-none'); opciones = []; activo = -1; }
    function elegir(i) { const p = opciones[i]; if (!p) return; cerrar(); buscador.value = ''; agregarLinea(p); }

    let temporizador = null;
    buscador.addEventListener('input', () => {
        clearTimeout(temporizador);
        const texto = buscador.value.trim();
        if (texto.length < 3) { cerrar(); return; }
        temporizador = setTimeout(async () => {
            try { const d = await buscar(texto); if (buscador.value.trim() === texto) mostrar(d.resultados); } catch (e) { /* reintenta con Enter */ }
        }, 300);
    });

    buscador.addEventListener('keydown', async (e) => {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!opciones.length) return;
            e.preventDefault();
            activo = (activo + (e.key === 'ArrowDown' ? 1 : -1) + opciones.length) % opciones.length;
            resultados.querySelectorAll('.list-group-item').forEach((el, i) => el.classList.toggle('activo', i === activo));
            return;
        }
        if (e.key === 'Escape') { cerrar(); return; }
        if (e.key !== 'Enter') return;
        e.preventDefault();
        clearTimeout(temporizador);
        const texto = buscador.value.trim();
        if (!texto) return;
        if (opciones.length && activo >= 0 && !resultados.classList.contains('d-none')) { elegir(activo); return; }
        try {
            const d = await buscar(texto);
            if (d.exacto || d.resultados.length === 1) { opciones = d.resultados; elegir(0); } else mostrar(d.resultados);
        } catch (err) { alert(err.message); }
    });

    resultados.addEventListener('mousedown', (e) => {
        const item = e.target.closest('[data-i]');
        if (item) { e.preventDefault(); elegir(parseInt(item.dataset.i, 10)); buscador.focus(); }
    });
    buscador.addEventListener('blur', () => setTimeout(cerrar, 150));

    document.getElementById('formOrden').addEventListener('submit', (e) => {
        if (!tbody.querySelector('tr.linea')) { e.preventDefault(); alert('Agregá al menos un producto.'); buscador.focus(); return; }
        document.getElementById('btnGuardar').disabled = true;
    });

    PREVIAS.forEach((l) => agregarLinea(l.producto, l));
    recalcular();
})();
</script>
@endpush
