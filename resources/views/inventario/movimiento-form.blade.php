{{-- resources/views/inventario/movimiento-form.blade.php --}}
@extends('layouts.app')

@section('titulo', $config['titulo'])

@push('styles')
<style>
    .buscador { position: relative; }
    .buscador .resultados { position: absolute; z-index: 1050; left: 0; right: 0; top: 100%; max-height: 320px; overflow-y: auto; }
    .buscador .resultados .list-group-item { cursor: pointer; }
    .buscador .resultados .list-group-item.activo { background: #ccfbf1; }
    .tabla-lineas input, .tabla-lineas select { min-width: 90px; }
    .fila-nueva { animation: resaltar 1.2s ease-out; }
    @keyframes resaltar { from { background: #ccfbf1; } to { background: transparent; } }
</style>
@endpush

@section('contenido')
    @php
        $modo = $config['modo'];
        $modoAjuste = old('modo_ajuste', 'contado');
    @endphp

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('inventario.movimientos.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0"><i class="bi {{ $config['icono'] }}"></i> {{ $config['titulo'] }}</h1>
            <span class="text-muted small">{{ $config['descripcion'] }}</span>
        </div>
    </div>

    <form method="POST" action="{{ route('inventario.movimientos.store', $tipo) }}" id="formMovimiento">
        @csrf

        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Depósito *</label>
                    <select name="deposito_id" id="deposito" class="form-select" required>
                        @foreach ($depositos as $d)
                            <option value="{{ $d->id }}" @selected($depositoInicial === $d->id)>{{ $d->etiqueta }}</option>
                        @endforeach
                    </select>
                </div>

                @if ($config['usa_motivo'])
                    <div class="col-md-4">
                        <label class="form-label">Motivo *</label>
                        <select name="motivo_id" id="motivo" class="form-select" required>
                            <option value="">Elegí…</option>
                            @foreach ($motivos as $m)
                                <option value="{{ $m->id }}" data-costo="{{ $m->pide_costo ? 1 : 0 }}" data-obs="{{ $m->requiere_observacion ? 1 : 0 }}"
                                        @selected((string) old('motivo_id') === (string) $m->id)>{{ $m->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($modo === 'ajuste')
                    <div class="col-md-4">
                        <label class="form-label">Cómo cargás el ajuste</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="modo_ajuste" id="modoContado" value="contado" @checked($modoAjuste === 'contado')>
                            <label class="btn btn-outline-secondary" for="modoContado"><i class="bi bi-123"></i> Lo que conté</label>
                            <input type="radio" class="btn-check" name="modo_ajuste" id="modoDiferencia" value="diferencia" @checked($modoAjuste === 'diferencia')>
                            <label class="btn btn-outline-secondary" for="modoDiferencia"><i class="bi bi-plus-slash-minus"></i> La diferencia</label>
                        </div>
                    </div>
                @endif

                <div class="col-md-4">
                    <label class="form-label">Comprobante</label>
                    <input type="text" name="comprobante" class="form-control" maxlength="100" value="{{ old('comprobante') }}" placeholder="Ej.: Remito 0001-00001234">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Fecha del comprobante</label>
                    <input type="date" name="fecha_comprobante" class="form-control" max="{{ today()->toDateString() }}" value="{{ old('fecha_comprobante') }}">
                </div>
                <div class="col-md-{{ $modo === 'ajuste' || ! $config['usa_motivo'] ? 4 : 8 }}">
                    <label class="form-label">Observaciones <span id="obsObligatoria" class="text-danger d-none">*</span></label>
                    <input type="text" name="observaciones" id="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones') }}">
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
                <div class="small text-muted mt-1" id="ayuda"></div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle mb-0 tabla-lineas">
                    <thead class="table-light">
                    <tr>
                        <th>Producto</th>
                        <th class="col-ubicacion" style="width: 210px;">Ubicación</th>
                        <th class="col-sistema text-end" style="width: 120px;">En sistema</th>
                        <th style="width: 160px;" id="tituloCantidad">Cantidad</th>
                        <th class="col-resultado text-end" style="width: 130px;" id="tituloResultado">Diferencia</th>
                        <th class="col-costo" style="width: 170px;">Costo unit. (sin IVA)</th>
                        <th class="col-costo text-end" style="width: 140px;">Subtotal</th>
                        <th style="width: 50px;"></th>
                    </tr>
                    </thead>
                    <tbody id="lineas">
                    <tr id="filaVacia">
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-upc-scan fs-1 d-block mb-2"></i>
                            Todavía no agregaste productos.
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-muted">
                    <span id="resumen">0 productos</span>
                    <span class="col-costo"> · Total: <strong id="total">$ 0,00</strong></span>
                </span>
                <button class="btn btn-stk" id="btnGuardar"><i class="bi bi-check-lg"></i> Registrar movimiento</button>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
(() => {
    const URL_BUSCAR = @json(route('catalogo.productos.buscar'));
    const URL_STOCK = @json(route('catalogo.productos.stock'));
    const MODO = @json($modo);                       // entrada | salida | ajuste
    const PIDE_COSTO = @json($config['pide_costo']);  // true | false | 'motivo'
    const UBICACIONES = @json($ubicaciones);
    const PREVIAS = @json($lineasPrevias);

    const tbody = document.getElementById('lineas');
    const filaVacia = document.getElementById('filaVacia');
    const buscador = document.getElementById('buscador');
    const resultados = document.getElementById('resultados');
    const selectDeposito = document.getElementById('deposito');
    const selectMotivo = document.getElementById('motivo');

    const formatoMoneda = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
    const formatoCantidad = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 4 });
    const fc = (n) => formatoCantidad.format(Math.abs(n) < 0.00005 ? 0 : n);
    const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    // Stock por producto en el depósito elegido: { productoId: { total, ubicaciones: { ubicacionId|0: cantidad } } }
    let stock = {};
    let indice = 0;
    let opciones = [];
    let activo = -1;

    /* ---------- Estado del formulario ---------- */
    function modoAjuste() {
        return document.querySelector('input[name="modo_ajuste"]:checked')?.value ?? 'contado';
    }

    function pideCosto() {
        if (PIDE_COSTO === 'motivo') {
            return selectMotivo?.selectedOptions[0]?.dataset.costo === '1';
        }
        return PIDE_COSTO === true;
    }

    function hayUbicaciones() {
        return (UBICACIONES[selectDeposito.value] ?? []).length > 0;
    }

    function textoSinUbicacion() {
        if (MODO === 'salida') return 'Tomar de donde haya';
        if (MODO === 'ajuste' && modoAjuste() === 'contado') return 'Todo el depósito';
        return 'Sin ubicar';
    }

    function opcionesUbicacion(seleccionada = '') {
        return `<option value="">${textoSinUbicacion()}</option>` + (UBICACIONES[selectDeposito.value] ?? [])
            .map((u) => `<option value="${u.id}" ${String(u.id) === String(seleccionada) ? 'selected' : ''}>${escapar(u.etiqueta)}</option>`)
            .join('');
    }

    function refrescarColumnas() {
        const ver = (selector, visible) => document.querySelectorAll(selector).forEach((el) => el.classList.toggle('d-none', !visible));
        ver('.col-ubicacion', hayUbicaciones());
        ver('.col-sistema', MODO !== 'entrada');
        ver('.col-resultado', MODO === 'ajuste');
        ver('.col-costo', pideCosto());

        document.getElementById('tituloCantidad').textContent =
            MODO === 'ajuste' ? (modoAjuste() === 'contado' ? 'Contado' : 'Diferencia (+/−)') : 'Cantidad';
        document.getElementById('tituloResultado').textContent = modoAjuste() === 'contado' ? 'Diferencia' : 'Queda en';

        document.getElementById('ayuda').textContent = {
            entrada: 'Si escaneás dos veces el mismo producto se suma a la línea existente.',
            salida: 'Si no elegís ubicación, se descuenta de donde haya stock. El sistema no deja sacar más de lo que hay (salvo que el depósito permita negativo).',
            ajuste: modoAjuste() === 'contado'
                ? 'Cargá la cantidad que contaste físicamente. El sistema calcula la diferencia al guardar; las líneas sin diferencia no se registran.'
                : 'Cargá la diferencia: positiva para sumar, negativa (con signo −) para restar.',
        }[MODO];

        tbody.querySelectorAll('input.cantidad').forEach((campo) => {
            campo.min = MODO === 'ajuste' && modoAjuste() === 'diferencia' ? '' : '0';
        });

        // Las observaciones obligatorias dependen del motivo
        const obligatoria = selectMotivo?.selectedOptions[0]?.dataset.obs === '1';
        document.getElementById('observaciones').required = obligatoria;
        document.getElementById('obsObligatoria').classList.toggle('d-none', !obligatoria);

        recalcular();
    }

    let depositoPrevio = selectDeposito.value;

    selectDeposito.addEventListener('change', async () => {
        const conUbicaciones = [...tbody.querySelectorAll('select.ubicacion')].some((sel) => sel.value !== '');
        if (conUbicaciones && !confirm('Al cambiar de depósito se borran las ubicaciones elegidas. ¿Continuar?')) {
            selectDeposito.value = depositoPrevio;
            return;
        }
        depositoPrevio = selectDeposito.value;
        tbody.querySelectorAll('select.ubicacion').forEach((sel) => { sel.innerHTML = opcionesUbicacion(''); });
        refrescarColumnas();
        await actualizarStock();
    });

    selectMotivo?.addEventListener('change', refrescarColumnas);
    document.querySelectorAll('input[name="modo_ajuste"]').forEach((radio) => radio.addEventListener('change', () => {
        tbody.querySelectorAll('select.ubicacion').forEach((sel) => {
            const valor = sel.value;
            sel.innerHTML = opcionesUbicacion(valor);
        });
        refrescarColumnas();
    }));

    /* ---------- Stock en sistema ---------- */
    async function actualizarStock() {
        const ids = [...new Set([...tbody.querySelectorAll('tr.linea')].map((f) => f.dataset.producto))];
        if (!ids.length || MODO === 'entrada') return;

        const url = new URL(URL_STOCK, window.location.origin);
        url.searchParams.set('deposito_id', selectDeposito.value);
        ids.forEach((id) => url.searchParams.append('ids[]', id));

        try {
            const respuesta = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (respuesta.ok) {
                stock = { ...stock, ...(await respuesta.json()) };
                recalcular();
            }
        } catch (error) { /* se verá al guardar */ }
    }

    function enSistema(fila) {
        const datos = stock[fila.dataset.producto];
        if (!datos) return null;

        // Sin ubicación se compara contra el total del depósito; con ubicación, contra esa ubicación
        const ubicacion = fila.querySelector('select.ubicacion')?.value ?? '';
        if (ubicacion === '') return datos.total;
        return datos.ubicaciones?.[ubicacion] ?? 0;
    }

    /* ---------- Líneas ---------- */
    function agregarLinea(producto, valores = {}) {
        const clave = `${producto.id}-${producto.presentacion?.id ?? 0}`;
        const existente = tbody.querySelector(`tr.linea[data-clave="${clave}"]`);

        if (producto.stock_ubicaciones !== undefined && producto.stock !== null) {
            stock[producto.id] = { total: producto.stock, ubicaciones: producto.stock_ubicaciones ?? {} };
        }

        // Mismo producto otra vez: en entradas y salidas suma 1; en ajustes enfoca la línea
        if (existente && valores.cantidad === undefined) {
            const campo = existente.querySelector('.cantidad');
            if (MODO !== 'ajuste') campo.value = (parseFloat(campo.value) || 0) + 1;
            resaltar(existente);
            campo.focus();
            campo.select();
            recalcular();
            return;
        }

        const i = indice++;
        const pres = producto.presentacion;
        const factor = pres ? pres.cantidad : 1;
        const unidadLinea = pres ? escapar(pres.nombre) : escapar(producto.unidad);
        const paso = (producto.permite_decimales && !pres) ? 'any' : '1';
        const cantidadInicial = valores.cantidad !== undefined ? valores.cantidad : (MODO === 'ajuste' ? '' : 1);
        const costoSugerido = valores.costo_unitario !== undefined && valores.costo_unitario !== ''
            ? valores.costo_unitario
            : producto.costo * factor;

        const fila = document.createElement('tr');
        fila.className = 'linea fila-nueva';
        fila.dataset.clave = clave;
        fila.dataset.producto = producto.id;
        fila.dataset.factor = factor;
        fila.dataset.unidad = producto.unidad ?? '';
        fila.innerHTML = `
            <td>
                <input type="hidden" name="lineas[${i}][producto_id]" value="${producto.id}">
                ${pres ? `<input type="hidden" name="lineas[${i}][presentacion_id]" value="${pres.id}">` : ''}
                <div class="fw-semibold">${escapar(producto.nombre)}</div>
                <div class="small text-muted">
                    <code>${escapar(producto.sku)}</code>
                    ${pres ? ` · <span class="badge text-bg-info">${escapar(pres.nombre)} = ${fc(pres.cantidad)} ${escapar(producto.unidad)}</span>` : ''}
                </div>
                <div class="small aviso"></div>
            </td>
            <td class="col-ubicacion">
                <select name="lineas[${i}][ubicacion_id]" class="form-select form-select-sm ubicacion">${opcionesUbicacion(valores.ubicacion_id ?? '')}</select>
            </td>
            <td class="col-sistema text-end sistema text-muted">—</td>
            <td>
                <div class="input-group input-group-sm">
                    <input type="number" name="lineas[${i}][cantidad]" class="form-control cantidad" step="${paso}" required
                           value="${escapar(cantidadInicial)}">
                    <span class="input-group-text">${unidadLinea}</span>
                </div>
            </td>
            <td class="col-resultado text-end resultado fw-semibold">—</td>
            <td class="col-costo">
                <div class="input-group input-group-sm">
                    <span class="input-group-text">$</span>
                    <input type="number" name="lineas[${i}][costo_unitario]" class="form-control costo" min="0" step="any"
                           value="${escapar(Math.round(costoSugerido * 10000) / 10000)}">
                </div>
            </td>
            <td class="col-costo text-end subtotal">$ 0,00</td>
            <td class="text-end">
                <button type="button" class="btn btn-sm btn-outline-danger quitar" title="Quitar"><i class="bi bi-x-lg"></i></button>
            </td>`;

        tbody.appendChild(fila);
        refrescarColumnas();

        if (valores.cantidad === undefined) {
            const campo = fila.querySelector('.cantidad');
            campo.focus();
            campo.select();
        }
    }

    function resaltar(fila) {
        fila.classList.remove('fila-nueva');
        void fila.offsetWidth;
        fila.classList.add('fila-nueva');
    }

    function recalcular() {
        const filas = tbody.querySelectorAll('tr.linea');
        const conCosto = pideCosto();
        let total = 0;

        filas.forEach((fila) => {
            const factor = parseFloat(fila.dataset.factor) || 1;
            const unidad = escapar(fila.dataset.unidad);
            const campoCantidad = fila.querySelector('.cantidad');
            const valor = campoCantidad.value === '' ? null : (parseFloat(campoCantidad.value) || 0) * factor;
            const sistema = enSistema(fila);
            const aviso = fila.querySelector('.aviso');
            aviso.innerHTML = '';

            // Costos (las entradas)
            const costo = parseFloat(fila.querySelector('.costo').value) || 0;
            const subtotal = (parseFloat(campoCantidad.value) || 0) * costo;
            if (conCosto) total += subtotal;
            fila.querySelector('.subtotal').textContent = formatoMoneda.format(subtotal);
            fila.querySelector('.costo').disabled = !conCosto;

            // Stock en sistema
            fila.querySelector('.sistema').textContent = sistema === null ? '—' : `${fc(sistema)} ${fila.dataset.unidad}`;

            if (MODO === 'salida' && sistema !== null && valor !== null && valor > sistema + 0.00005) {
                aviso.innerHTML = `<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Supera el stock (${fc(sistema)} ${unidad})</span>`;
            }

            if (MODO === 'ajuste') {
                const celda = fila.querySelector('.resultado');
                if (sistema === null || valor === null) {
                    celda.textContent = '—';
                    celda.className = 'col-resultado text-end resultado fw-semibold';
                } else if (modoAjuste() === 'contado') {
                    const diferencia = valor - sistema;
                    celda.textContent = Math.abs(diferencia) < 0.00005 ? 'Sin diferencia' : `${diferencia > 0 ? '+' : '−'}${fc(Math.abs(diferencia))}`;
                    celda.className = `col-resultado text-end resultado fw-semibold ${diferencia > 0 ? 'text-success' : (diferencia < 0 ? 'text-danger' : 'text-muted')}`;
                } else {
                    const queda = sistema + valor;
                    celda.textContent = `${fc(queda)} ${fila.dataset.unidad}`;
                    celda.className = `col-resultado text-end resultado fw-semibold ${queda < 0 ? 'text-danger' : ''}`;
                }
            }
        });

        document.getElementById('total').textContent = formatoMoneda.format(total);
        document.getElementById('resumen').textContent = `${filas.length} ${filas.length === 1 ? 'producto' : 'productos'}`;
        filaVacia.classList.toggle('d-none', filas.length > 0);
    }

    tbody.addEventListener('input', (e) => {
        if (e.target.matches('.cantidad, .costo')) recalcular();
    });

    tbody.addEventListener('change', (e) => {
        if (e.target.matches('select.ubicacion')) recalcular();
    });

    tbody.addEventListener('click', (e) => {
        const boton = e.target.closest('.quitar');
        if (boton) {
            boton.closest('tr').remove();
            recalcular();
            buscador.focus();
        }
    });

    tbody.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && e.target.matches('input')) {
            e.preventDefault();
            buscador.focus();
        }
    });

    /* ---------- Buscador / lector ---------- */
    async function buscar(texto) {
        const url = new URL(URL_BUSCAR, window.location.origin);
        url.searchParams.set('q', texto);
        url.searchParams.set('stockeables', '1');
        url.searchParams.set('deposito_id', selectDeposito.value);

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

    function cerrarResultados() {
        resultados.classList.add('d-none');
        opciones = [];
        activo = -1;
    }

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
            } catch (error) { /* se reintenta con Enter */ }
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

        if (opciones.length && activo >= 0 && !resultados.classList.contains('d-none')) {
            elegir(activo);
            return;
        }

        try {
            const numero = ++ultimaBusqueda;
            const datos = await buscar(texto);
            if (numero !== ultimaBusqueda) return;

            if (datos.exacto || datos.resultados.length === 1) {
                opciones = datos.resultados;
                elegir(0);
            } else {
                mostrarResultados(datos.resultados);
            }
        } catch (error) {
            alert(error.message);
        }
    });

    resultados.addEventListener('mousedown', (e) => {
        const item = e.target.closest('[data-i]');
        if (item) {
            e.preventDefault();
            elegir(parseInt(item.dataset.i, 10));
            buscador.focus();
        }
    });

    buscador.addEventListener('blur', () => setTimeout(cerrarResultados, 150));

    /* ---------- Envío ---------- */
    document.getElementById('formMovimiento').addEventListener('submit', (e) => {
        if (!tbody.querySelector('tr.linea')) {
            e.preventDefault();
            alert('Agregá al menos un producto.');
            buscador.focus();
            return;
        }
        document.getElementById('btnGuardar').disabled = true;
    });

    /* ---------- Inicio ---------- */
    PREVIAS.forEach((linea) => agregarLinea(linea.producto, linea));
    refrescarColumnas();
    actualizarStock();
    buscador.focus();
})();
</script>
@endpush
