{{-- resources/views/inventario/transferencia-form.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Nueva transferencia')

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

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('inventario.transferencias.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-truck"></i> Nueva transferencia</h1>
            <span class="text-muted small">La mercadería sale del origen y queda "en tránsito" hasta que la reciban en destino.</span>
        </div>
    </div>

    <form method="POST" action="{{ route('inventario.transferencias.store') }}" id="formTransferencia">
        @csrf

        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Desde *</label>
                    <select name="deposito_origen_id" id="origen" class="form-select" required>
                        @foreach ($origenes as $d)
                            <option value="{{ $d->id }}" @selected($origenInicial === $d->id)>{{ $d->etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Hacia *</label>
                    <select name="deposito_destino_id" id="destino" class="form-select" required>
                        <option value="">Elegí…</option>
                        @foreach ($destinos as $d)
                            <option value="{{ $d->id }}" @selected((string) old('deposito_destino_id') === (string) $d->id)>{{ $d->etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Transportista / chofer</label>
                    <input type="text" name="transportista" class="form-control" maxlength="150" value="{{ old('transportista') }}">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones') }}">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="recibir_ahora" id="recibirAhora" value="1" @checked(old('recibir_ahora'))>
                        <label class="form-check-label" for="recibirAhora">Recibir en el momento</label>
                        <div class="form-text">Para movimientos entre depósitos del mismo lugar.</div>
                    </div>
                </div>
            </div>
        </div>

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
                <div class="small text-muted mt-1">Si no elegís ubicación, se toma de donde haya stock en el origen.</div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Producto</th>
                        <th class="col-ubicacion" style="width: 200px;">Ubicación de origen</th>
                        <th class="col-lote" style="width: 230px;">Lote</th>
                        <th class="text-end" style="width: 130px;">En origen</th>
                        <th style="width: 170px;">Cantidad a enviar</th>
                        <th style="width: 50px;"></th>
                    </tr>
                    </thead>
                    <tbody id="lineas">
                    <tr id="filaVacia">
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="bi bi-upc-scan fs-1 d-block mb-2"></i>
                            Todavía no agregaste productos.
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                <span class="small text-muted" id="resumen">0 productos</span>
                <button class="btn btn-stk" id="btnGuardar"><i class="bi bi-send"></i> Enviar</button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
(() => {
    const URL_BUSCAR = @json(route('catalogo.productos.buscar'));
    const URL_STOCK = @json(route('catalogo.productos.stock'));
    const UBICACIONES = @json($ubicaciones);
    const PREVIAS = @json($lineasPrevias);

    const tbody = document.getElementById('lineas');
    const filaVacia = document.getElementById('filaVacia');
    const buscador = document.getElementById('buscador');
    const resultados = document.getElementById('resultados');
    const selectOrigen = document.getElementById('origen');
    const selectDestino = document.getElementById('destino');

    const formatoCantidad = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 4 });
    const fc = (n) => formatoCantidad.format(Math.abs(n) < 0.00005 ? 0 : n);
    const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    let stock = {};
    let indice = 0;
    let opciones = [];
    let activo = -1;

    /* ---------- Depósitos ---------- */
    function hayUbicaciones() {
        return (UBICACIONES[selectOrigen.value] ?? []).length > 0;
    }

    function opcionesUbicacion(seleccionada = '') {
        return '<option value="">Tomar de donde haya</option>' + (UBICACIONES[selectOrigen.value] ?? [])
            .map((u) => `<option value="${u.id}" ${String(u.id) === String(seleccionada) ? 'selected' : ''}>${escapar(u.etiqueta)}</option>`)
            .join('');
    }

    function sincronizarDestino() {
        // No se puede elegir el mismo depósito como destino
        [...selectDestino.options].forEach((op) => {
            op.disabled = op.value !== '' && op.value === selectOrigen.value;
            if (op.disabled && op.selected) selectDestino.value = '';
        });
    }

    function refrescarColumnas() {
        document.querySelectorAll('.col-ubicacion').forEach((el) => el.classList.toggle('d-none', !hayUbicaciones()));
        const hayLotes = !!tbody.querySelector('tr.linea[data-lotes="1"]');
        document.querySelectorAll('.col-lote').forEach((el) => el.classList.toggle('d-none', !hayLotes));
        recalcular();
    }

    /* ---------- Lotes ---------- */
    function fechaCorta(iso) {
        if (!iso) return '';
        const [a, m, d] = iso.split('-');
        return `${d}/${m}/${a}`;
    }

    function refrescarLotes() {
        tbody.querySelectorAll('tr.linea[data-lotes="1"]').forEach((fila) => {
            const select = fila.querySelector('select.lote');
            const actual = select.value || fila.dataset.loteInicial || '';
            select.innerHTML = '<option value="">Automático (vence antes primero)</option>' + (stock[fila.dataset.producto]?.lotes ?? [])
                .map((l) => `<option value="${l.id}" ${String(l.id) === String(actual) ? 'selected' : ''}>`
                    + `${escapar(l.codigo)}${l.vencimiento ? ' · vence ' + fechaCorta(l.vencimiento) : ''} · ${fc(l.cantidad)}${l.vencido ? ' · VENCIDO' : ''}</option>`)
                .join('');
        });
    }

    let origenPrevio = selectOrigen.value;
    selectOrigen.addEventListener('change', async () => {
        const conUbicaciones = [...tbody.querySelectorAll('select.ubicacion')].some((s) => s.value !== '');
        if (conUbicaciones && !confirm('Al cambiar el origen se borran las ubicaciones elegidas. ¿Continuar?')) {
            selectOrigen.value = origenPrevio;
            return;
        }
        origenPrevio = selectOrigen.value;
        tbody.querySelectorAll('select.ubicacion').forEach((s) => { s.innerHTML = opcionesUbicacion(''); });
        sincronizarDestino();
        refrescarColumnas();
        await actualizarStock();
    });

    /* ---------- Stock en origen ---------- */
    async function actualizarStock() {
        const ids = [...new Set([...tbody.querySelectorAll('tr.linea')].map((f) => f.dataset.producto))];
        if (!ids.length) return;

        const url = new URL(URL_STOCK, window.location.origin);
        url.searchParams.set('deposito_id', selectOrigen.value);
        ids.forEach((id) => url.searchParams.append('ids[]', id));

        try {
            const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (respuesta.ok) {
                stock = { ...stock, ...(await respuesta.json()) };
                refrescarLotes();
                recalcular();
            }
        } catch (error) { /* se valida al guardar */ }
    }

    function enOrigen(fila) {
        const datos = stock[fila.dataset.producto];
        if (!datos) return null;
        const loteId = fila.querySelector('select.lote')?.value;
        if (loteId) return (datos.lotes ?? []).find((l) => String(l.id) === loteId)?.cantidad ?? 0;
        const ubicacion = fila.querySelector('select.ubicacion')?.value ?? '';
        return ubicacion === '' ? datos.total : (datos.ubicaciones?.[ubicacion] ?? 0);
    }

    /* ---------- Líneas ---------- */
    function agregarLinea(producto, valores = {}) {
        if (producto.stock_ubicaciones !== undefined && producto.stock !== null) {
            stock[producto.id] = { total: producto.stock, ubicaciones: producto.stock_ubicaciones ?? {}, lotes: producto.stock_lotes ?? [] };
        }

        const conSeries = !!producto.usa_series;
        const conLotes = !!producto.usa_lotes;

        // Si se escaneó una presentación (ej. Pack x 6) se suman sus unidades
        const incremento = producto.presentacion ? producto.presentacion.cantidad : 1;
        const existente = tbody.querySelector(`tr.linea[data-producto="${producto.id}"]`);

        if (existente && valores.cantidad === undefined && !conSeries && !conLotes) {
            const campo = existente.querySelector('.cantidad');
            campo.value = (parseFloat(campo.value) || 0) + incremento;
            existente.classList.remove('fila-nueva'); void existente.offsetWidth; existente.classList.add('fila-nueva');
            recalcular();
            return;
        }

        const i = indice++;
        const fila = document.createElement('tr');
        fila.className = 'linea fila-nueva';
        fila.dataset.producto = producto.id;
        fila.dataset.unidad = producto.unidad ?? '';
        fila.dataset.lotes = conLotes ? '1' : '0';
        fila.dataset.loteInicial = valores.lote_id ?? '';
        const series = Array.isArray(valores.series) ? valores.series.join('\n') : (valores.series ?? '');
        fila.innerHTML = `
            <td>
                <input type="hidden" name="lineas[${i}][producto_id]" value="${producto.id}">
                <div class="fw-semibold">${escapar(producto.nombre)}</div>
                <div class="small text-muted"><code>${escapar(producto.sku)}</code></div>
                ${conSeries ? `<textarea name="lineas[${i}][series]" class="form-control form-control-sm font-monospace series mt-1" rows="2"
                    placeholder="Números de serie (uno por línea)">${escapar(series)}</textarea>` : ''}
                <div class="small aviso"></div>
            </td>
            <td class="col-ubicacion">
                <select name="lineas[${i}][ubicacion_id]" class="form-select form-select-sm ubicacion">${opcionesUbicacion(valores.ubicacion_id ?? '')}</select>
            </td>
            <td class="col-lote">${conLotes ? `<select name="lineas[${i}][lote_id]" class="form-select form-select-sm lote"></select>` : '<span class="text-muted small">—</span>'}</td>
            <td class="text-end text-muted origen">—</td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="lineas[${i}][cantidad]" class="form-control cantidad" min="0"
                           step="${producto.permite_decimales && !conSeries ? 'any' : '1'}" required
                           value="${escapar(valores.cantidad ?? (conSeries ? '' : incremento))}" ${conSeries ? 'readonly tabindex="-1"' : ''}>
                    <span class="input-group-text">${escapar(producto.unidad)}</span>
                </div>
            </td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger quitar"><i class="bi bi-x-lg"></i></button>
            </td>`;

        tbody.appendChild(fila);
        refrescarLotes();
        refrescarColumnas();

        if (valores.cantidad === undefined) {
            const campo = fila.querySelector(conSeries ? '.series' : '.cantidad');
            campo.focus();
            campo.select?.();
        }
    }

    function recalcular() {
        const filas = tbody.querySelectorAll('tr.linea');

        filas.forEach((fila) => {
            const disponible = enOrigen(fila);
            const cantidad = parseFloat(fila.querySelector('.cantidad').value) || 0;
            fila.querySelector('.origen').textContent = disponible === null ? '—' : `${fc(disponible)} ${fila.dataset.unidad}`;
            fila.querySelector('.aviso').innerHTML = disponible !== null && cantidad > disponible + 0.00005
                ? `<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Supera el stock en origen</span>` : '';
        });

        document.getElementById('resumen').textContent = `${filas.length} ${filas.length === 1 ? 'producto' : 'productos'}`;
        filaVacia.classList.toggle('d-none', filas.length > 0);
    }

    tbody.addEventListener('input', (e) => {
        if (e.target.matches('.series')) {
            const numeros = [...new Set(e.target.value.split(/[\r\n,;]+/).map((n) => n.trim()).filter(Boolean))];
            e.target.closest('tr').querySelector('.cantidad').value = numeros.length || '';
        }
        if (e.target.matches('.cantidad, .series')) recalcular();
    });
    tbody.addEventListener('change', (e) => { if (e.target.matches('select.ubicacion, select.lote')) recalcular(); });
    tbody.addEventListener('click', (e) => {
        const boton = e.target.closest('.quitar');
        if (boton) { boton.closest('tr').remove(); recalcular(); buscador.focus(); }
    });
    tbody.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('input')) { e.preventDefault(); buscador.focus(); }
    });

    /* ---------- Buscador / lector ---------- */
    async function buscar(texto) {
        const url = new URL(URL_BUSCAR, window.location.origin);
        url.searchParams.set('q', texto);
        url.searchParams.set('stockeables', '1');
        url.searchParams.set('deposito_id', selectOrigen.value);
        const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
        if (!respuesta.ok) throw new Error('No se pudo buscar el producto.');
        return respuesta.json();
    }

    function mostrarResultados(lista) {
        opciones = lista;
        activo = lista.length ? 0 : -1;
        resultados.innerHTML = !lista.length
            ? '<div class="list-group-item text-muted">No se encontraron productos que controlen stock.</div>'
            : lista.map((p, i) => `
                <div class="list-group-item ${i === 0 ? 'activo' : ''}" data-i="${i}">
                    <div class="d-flex justify-content-between">
                        <span class="fw-semibold">${escapar(p.nombre)}</span>
                        ${p.stock !== null ? `<span class="small text-muted">${fc(p.stock)} ${escapar(p.unidad)}</span>` : ''}
                    </div>
                    <div class="small text-muted"><code>${escapar(p.sku)}</code></div>
                </div>`).join('');
        resultados.classList.remove('d-none');
    }

    function cerrarResultados() { resultados.classList.add('d-none'); opciones = []; activo = -1; }

    function elegir(i) {
        const producto = opciones[i];
        if (!producto) return;
        cerrarResultados();
        buscador.value = '';
        agregarLinea(producto);
    }

    let temporizador = null;
    let ultimaBusqueda = 0;

    buscador.addEventListener('input', () => {
        clearTimeout(temporizador);
        const texto = buscador.value.trim();
        if (texto.length < 3) { cerrarResultados(); return; }
        temporizador = setTimeout(async () => {
            const numero = ++ultimaBusqueda;
            try {
                const datos = await buscar(texto);
                if (numero === ultimaBusqueda && buscador.value.trim() === texto) mostrarResultados(datos.resultados);
            } catch (error) { /* reintenta con Enter */ }
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
        if (e.key === 'Escape') { cerrarResultados(); return; }
        if (e.key !== 'Enter') return;

        e.preventDefault();
        clearTimeout(temporizador);
        const texto = buscador.value.trim();
        if (!texto) return;

        if (opciones.length && activo >= 0 && !resultados.classList.contains('d-none')) { elegir(activo); return; }

        try {
            const numero = ++ultimaBusqueda;
            const datos = await buscar(texto);
            if (numero !== ultimaBusqueda) return;
            if (datos.exacto || datos.resultados.length === 1) { opciones = datos.resultados; elegir(0); }
            else mostrarResultados(datos.resultados);
        } catch (error) {
            alert(error.message);
        }
    });

    resultados.addEventListener('mousedown', (e) => {
        const item = e.target.closest('[data-i]');
        if (item) { e.preventDefault(); elegir(parseInt(item.dataset.i, 10)); buscador.focus(); }
    });
    buscador.addEventListener('blur', () => setTimeout(cerrarResultados, 150));

    document.getElementById('formTransferencia').addEventListener('submit', (e) => {
        if (!tbody.querySelector('tr.linea')) {
            e.preventDefault();
            alert('Agregá al menos un producto.');
            buscador.focus();
            return;
        }
        document.getElementById('btnGuardar').disabled = true;
    });

    PREVIAS.forEach((linea) => agregarLinea(linea.producto, linea));
    sincronizarDestino();
    refrescarColumnas();
    actualizarStock();
    buscador.focus();
})();
</script>
@endpush
