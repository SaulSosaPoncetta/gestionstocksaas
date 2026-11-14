{{--
    resources/views/salidas/partials/selector-productos.blade.php
    Buscador con lector + tabla de renglones para salidas (reservas y remitos).

    Variables:
      $selector = [
        'ubicaciones' => bool,   // columna de ubicación
        'series'      => bool,   // caja de números de serie para productos que los usan
        'lineas'      => [...],  // renglones previos (old input o la reserva)
      ]
    Requiere en la página un <select id="deposito">.
--}}
@php($opciones = array_merge(['ubicaciones' => false, 'series' => false, 'lineas' => []], $selector ?? []))

@once
    @push('styles')
    <style>
        .buscador { position: relative; }
        .buscador .resultados { position: absolute; z-index: 1050; left: 0; right: 0; top: 100%; max-height: 320px; overflow-y: auto; }
        .buscador .resultados .list-group-item { cursor: pointer; }
        .buscador .resultados .list-group-item.activo { background: #ccfbf1; }
        .fila-nueva { animation: resaltar 1.2s ease-out; }
        @keyframes resaltar { from { background: #ccfbf1; } to { background: transparent; } }
    </style>
    @endpush
@endonce

<div class="card">
    <div class="card-header bg-white">
        <div class="buscador">
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                <input type="text" id="buscador" class="form-control form-control-lg" autocomplete="off"
                       placeholder="Escaneá un código de barras o escribí nombre / SKU y Enter">
            </div>
            <div class="resultados list-group shadow d-none" id="resultados"></div>
        </div>
        <div class="small text-muted mt-1">Se compara contra el stock <strong>disponible</strong> (real menos lo reservado).</div>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Producto</th>
                <th class="col-ubicacion" style="width: 190px;">Ubicación</th>
                <th class="col-lote" style="width: 230px;">Lote</th>
                <th class="text-end" style="width: 130px;">Disponible</th>
                <th style="width: 160px;">Cantidad</th>
                <th style="width: 50px;"></th>
            </tr>
            </thead>
            <tbody id="lineas">
            <tr id="filaVacia"><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-upc-scan fs-1 d-block mb-2"></i>Todavía no agregaste productos.</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted" id="resumen">0 productos</div>
</div>

@push('scripts')
<script>
(() => {
    const URL_BUSCAR = @json(route('catalogo.productos.buscar'));
    const URL_STOCK = @json(route('catalogo.productos.stock'));
    const CON_UBICACIONES = @json($opciones['ubicaciones']);
    const CON_SERIES = @json($opciones['series']);
    const UBICACIONES = @json($ubicaciones ?? []);
    const PREVIAS = @json($opciones['lineas']);

    const tbody = document.getElementById('lineas');
    const filaVacia = document.getElementById('filaVacia');
    const buscador = document.getElementById('buscador');
    const resultados = document.getElementById('resultados');
    const selectDeposito = document.getElementById('deposito');

    const formato = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 4 });
    const fc = (n) => formato.format(Math.abs(n) < 0.00005 ? 0 : n);
    const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const fecha = (iso) => { if (!iso) return ''; const [a, m, d] = iso.split('-'); return `${d}/${m}/${a}`; };

    let stock = {};
    let indice = 0;
    let opciones = [];
    let activo = -1;

    const deposito = () => selectDeposito?.value;
    const hayUbicaciones = () => CON_UBICACIONES && (UBICACIONES[deposito()] ?? []).length > 0;

    function opcionesUbicacion(sel = '') {
        return '<option value="">Tomar de donde haya</option>' + (UBICACIONES[deposito()] ?? [])
            .map((u) => `<option value="${u.id}" ${String(u.id) === String(sel) ? 'selected' : ''}>${escapar(u.etiqueta)}</option>`).join('');
    }

    function opcionesLote(productoId, sel = '') {
        return '<option value="">Automático (vence antes primero)</option>' + (stock[productoId]?.lotes ?? [])
            .map((l) => `<option value="${l.id}" ${String(l.id) === String(sel) ? 'selected' : ''}>${escapar(l.codigo)}${l.vencimiento ? ' · vence ' + fecha(l.vencimiento) : ''} · ${fc(l.cantidad)}${l.vencido ? ' · VENCIDO' : ''}</option>`).join('');
    }

    function refrescar() {
        document.querySelectorAll('.col-ubicacion').forEach((el) => el.classList.toggle('d-none', !hayUbicaciones()));
        document.querySelectorAll('.col-lote').forEach((el) => el.classList.toggle('d-none', !tbody.querySelector('tr.linea[data-lotes="1"]')));

        tbody.querySelectorAll('tr.linea').forEach((fila) => {
            const sel = fila.querySelector('select.lote');
            if (sel) sel.innerHTML = opcionesLote(fila.dataset.producto, sel.value || fila.dataset.loteInicial || '');

            const datos = stock[fila.dataset.producto];
            const celda = fila.querySelector('.disponible');
            const aviso = fila.querySelector('.aviso');
            const cantidad = parseFloat(fila.querySelector('.cantidad').value) || 0;

            if (fila.dataset.reservaItem) {
                celda.innerHTML = `<span class="text-warning-emphasis">Reservado: ${fc(parseFloat(fila.dataset.pendiente))}</span>`;
                aviso.innerHTML = cantidad > parseFloat(fila.dataset.pendiente) + 0.00005 ? '<span class="text-danger">Supera lo reservado</span>' : '';
                return;
            }

            if (!datos) { celda.textContent = '—'; return; }
            const loteId = fila.querySelector('select.lote')?.value;
            const disponible = loteId ? ((datos.lotes ?? []).find((l) => String(l.id) === loteId)?.cantidad ?? 0) : (datos.disponible ?? datos.total);
            celda.textContent = `${fc(disponible)} ${fila.dataset.unidad}`;
            aviso.innerHTML = cantidad > disponible + 0.00005
                ? `<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Supera el disponible${datos.reservado > 0 ? ` (${fc(datos.reservado)} reservadas)` : ''}</span>` : '';
        });

        const n = tbody.querySelectorAll('tr.linea').length;
        document.getElementById('resumen').textContent = `${n} ${n === 1 ? 'producto' : 'productos'}`;
        filaVacia.classList.toggle('d-none', n > 0);
    }

    async function actualizarStock() {
        const ids = [...new Set([...tbody.querySelectorAll('tr.linea')].map((f) => f.dataset.producto))];
        if (!ids.length || !deposito()) return;
        const url = new URL(URL_STOCK, window.location.origin);
        url.searchParams.set('deposito_id', deposito());
        ids.forEach((id) => url.searchParams.append('ids[]', id));
        try {
            const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (r.ok) { stock = { ...stock, ...(await r.json()) }; refrescar(); }
        } catch (e) { /* se valida al guardar */ }
    }

    function agregarLinea(p, valores = {}) {
        if (p.stock !== undefined && p.stock !== null) {
            stock[p.id] = { total: p.stock, reservado: p.stock_reservado ?? 0, disponible: p.stock_disponible ?? p.stock, ubicaciones: p.stock_ubicaciones ?? {}, lotes: p.stock_lotes ?? [] };
        }

        const conSeries = CON_SERIES && !!p.usa_series;
        const conLotes = !!p.usa_lotes;
        const esReserva = !!valores.reserva_item_id;
        const incremento = p.presentacion ? p.presentacion.cantidad : 1;
        const existente = tbody.querySelector(`tr.linea[data-producto="${p.id}"]:not([data-reserva-item])`);

        if (existente && valores.cantidad === undefined && !conSeries && !conLotes) {
            const c = existente.querySelector('.cantidad');
            c.value = (parseFloat(c.value) || 0) + incremento;
            existente.classList.remove('fila-nueva'); void existente.offsetWidth; existente.classList.add('fila-nueva');
            refrescar();
            return;
        }

        const i = indice++;
        const fila = document.createElement('tr');
        fila.className = 'linea fila-nueva';
        fila.dataset.producto = p.id;
        fila.dataset.unidad = p.unidad ?? '';
        fila.dataset.lotes = conLotes ? '1' : '0';
        fila.dataset.loteInicial = valores.lote_id ?? '';
        if (esReserva) { fila.dataset.reservaItem = valores.reserva_item_id; fila.dataset.pendiente = valores.pendiente ?? valores.cantidad; }

        const series = Array.isArray(valores.series) ? valores.series.join('\n') : (valores.series ?? '');
        const celdaLote = !conLotes ? '<span class="text-muted small">—</span>'
            : (esReserva && valores.lote_id
                ? `<input type="hidden" name="lineas[${i}][lote_id]" value="${valores.lote_id}"><span class="badge text-bg-light border">Lote ${escapar(valores.lote_texto ?? '')}</span>`
                : `<select name="lineas[${i}][lote_id]" class="form-select form-select-sm lote"></select>`);

        fila.innerHTML = `
            <td>
                <input type="hidden" name="lineas[${i}][producto_id]" value="${p.id}">
                ${esReserva ? `<input type="hidden" name="lineas[${i}][reserva_item_id]" value="${valores.reserva_item_id}">` : ''}
                <div class="fw-semibold">${escapar(p.nombre)} ${esReserva ? '<span class="badge text-bg-warning">De la reserva</span>' : ''}</div>
                <div class="small text-muted"><code>${escapar(p.sku)}</code></div>
                ${conSeries ? `<textarea name="lineas[${i}][series]" class="form-control form-control-sm font-monospace series mt-1" rows="2" placeholder="Números de serie (uno por línea)">${escapar(series)}</textarea>` : ''}
                <div class="small aviso"></div>
            </td>
            <td class="col-ubicacion">${CON_UBICACIONES ? `<select name="lineas[${i}][ubicacion_id]" class="form-select form-select-sm ubicacion">${opcionesUbicacion(valores.ubicacion_id ?? '')}</select>` : ''}</td>
            <td class="col-lote">${celdaLote}</td>
            <td class="text-end text-muted disponible">—</td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="lineas[${i}][cantidad]" class="form-control cantidad" min="0"
                           step="${p.permite_decimales && !conSeries ? 'any' : '1'}" required
                           value="${escapar(valores.cantidad ?? (conSeries ? '' : incremento))}" ${conSeries ? 'readonly tabindex="-1"' : ''}>
                    <span class="input-group-text">${escapar(p.unidad)}</span>
                </div>
            </td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger quitar" title="Quitar"><i class="bi bi-x-lg"></i></button></td>`;

        tbody.appendChild(fila);
        refrescar();

        if (valores.cantidad === undefined) {
            const c = fila.querySelector(conSeries ? '.series' : '.cantidad');
            c.focus(); c.select?.();
        }
    }

    tbody.addEventListener('input', (e) => {
        if (e.target.matches('.series')) {
            const numeros = [...new Set(e.target.value.split(/[\r\n,;]+/).map((n) => n.trim()).filter(Boolean))];
            e.target.closest('tr').querySelector('.cantidad').value = numeros.length || '';
        }
        if (e.target.matches('.cantidad, .series')) refrescar();
    });
    tbody.addEventListener('change', (e) => { if (e.target.matches('select')) refrescar(); });
    tbody.addEventListener('click', (e) => {
        const b = e.target.closest('.quitar');
        if (b) { b.closest('tr').remove(); refrescar(); buscador.focus(); }
    });
    tbody.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); buscador.focus(); }
    });

    selectDeposito?.addEventListener('change', () => {
        tbody.querySelectorAll('select.ubicacion').forEach((s) => { s.innerHTML = opcionesUbicacion(''); });
        actualizarStock();
        refrescar();
    });

    /* ---------- Buscador / lector ---------- */
    async function buscar(texto) {
        const url = new URL(URL_BUSCAR, window.location.origin);
        url.searchParams.set('q', texto);
        url.searchParams.set('stockeables', '1');
        if (deposito()) url.searchParams.set('deposito_id', deposito());
        const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) throw new Error('No se pudo buscar el producto.');
        return r.json();
    }

    function mostrar(lista) {
        opciones = lista; activo = lista.length ? 0 : -1;
        resultados.innerHTML = lista.length
            ? lista.map((p, i) => `<div class="list-group-item ${i === 0 ? 'activo' : ''}" data-i="${i}"><div class="d-flex justify-content-between"><span class="fw-semibold">${escapar(p.nombre)}</span>${p.stock_disponible !== null && p.stock_disponible !== undefined ? `<span class="small text-muted">${fc(p.stock_disponible)} disp.</span>` : ''}</div><code class="small">${escapar(p.sku)}</code></div>`).join('')
            : '<div class="list-group-item text-muted">No se encontraron productos que controlen stock.</div>';
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

    // Para el formulario: saber si hay renglones
    window.selectorTieneLineas = () => !!tbody.querySelector('tr.linea');

    PREVIAS.forEach((l) => agregarLinea(l.producto, l));
    refrescar();
    actualizarStock();
    if (!PREVIAS.length) buscador.focus();
})();
</script>
@endpush
