{{-- resources/views/inventario/toma.blade.php (conteo + resultado) --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Toma de inventario N° ' . $toma->numero_texto)

@push('styles')
<style>
    #tablaConteo tr.cambiado td { background: #fff8e1; }
    #tablaConteo tr.resaltado td { background: #d1f2eb !important; transition: background .2s; }
    #tablaConteo .input-contado { max-width: 130px; margin-left: auto; text-align: end; }
    .barra-guardar { position: sticky; bottom: 0; z-index: 5; }
</style>
@endpush

@section('contenido')
    @php($enCurso = $toma->estaEnCurso())

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('inventario.tomas.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">Toma de inventario N° {{ $toma->numero_texto }}
                <span class="badge text-bg-{{ $toma->color }} align-middle">{{ $toma->estado_nombre }}</span>
                @if ($enCurso && $toma->congela)<span class="badge text-bg-info align-middle"><i class="bi bi-snow"></i> Depósito congelado</span>@endif
            </h1>
            <span class="text-muted small">{{ $toma->deposito?->nombre }} · {{ $toma->alcance }}{{ $toma->descripcion ? ' · ' . $toma->descripcion : '' }}</span>
        </div>
        <a href="{{ route('inventario.tomas.planilla', $toma) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer"></i> Planilla</a>
        @if ($enCurso && $puedeAdministrar)
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCerrar"><i class="bi bi-check2-all"></i> Cerrar y ajustar</button>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalCancelar"><i class="bi bi-x-octagon"></i></button>
        @endif
        @if ($toma->movimiento)
            <a href="{{ route('inventario.movimientos.show', $toma->movimiento) }}" class="btn btn-outline-secondary"><i class="bi bi-journal-text"></i> Ajuste N° {{ $toma->movimiento->numero_texto }}</a>
        @endif
    </div>

    @if ($toma->estado === 'cancelada')
        <div class="alert alert-secondary small"><i class="bi bi-x-octagon"></i> Cancelada el {{ $toma->cerrada_at?->format('d/m/Y H:i') }}: {{ $toma->motivo_cancelacion }}. No se ajustó nada.</div>
    @endif
    @if ($toma->movimiento?->estaAnulado())
        <div class="alert alert-dark small"><i class="bi bi-arrow-counterclockwise"></i> El ajuste de esta toma fue anulado: el stock volvió a como estaba antes de cerrarla.</div>
    @endif

    {{-- Resumen --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Avance</div>
                <div class="h4 mb-1"><span id="resContados">{{ $resumen['contados'] }}</span> / {{ $resumen['total'] }}</div>
                <div class="progress" style="height: 6px;"><div class="progress-bar bg-success" id="resBarra" style="width: {{ $resumen['avance'] }}%"></div></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Pendientes de contar</div>
                <div class="h4 mb-0 {{ $resumen['pendientes'] ? 'text-warning-emphasis' : 'text-success' }}">{{ $resumen['pendientes'] }}</div>
            </div></div>
        </div>
        @if ($muestraTeorico)
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted small">Faltante valorizado</div>
                    <div class="h4 mb-0 text-danger">{{ Formato::moneda($enCurso ? $resumen['faltante'] : $toma->valor_faltante) }}</div>
                    <div class="small text-muted">{{ $resumen['con_diferencia'] }} renglones con diferencia</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted small">Sobrante valorizado</div>
                    <div class="h4 mb-0 text-success">{{ Formato::moneda($enCurso ? $resumen['sobrante'] : $toma->valor_sobrante) }}</div>
                    <div class="small text-muted">a costo promedio</div>
                </div></div>
            </div>
        @else
            <div class="col-lg-6">
                <div class="alert alert-light border small h-100 mb-0"><i class="bi bi-eye-slash"></i> Conteo <strong>a ciegas</strong>: contá lo que hay físicamente, sin mirar el sistema. Las diferencias las revisa el encargado.</div>
            </div>
        @endif
    </div>

    @if ($enCurso)
        {{-- Lector / búsqueda --}}
        <div class="card mb-3">
            <div class="card-body row g-2 align-items-center">
                <div class="col-lg-5">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                        <input type="text" id="escaner" class="form-control" placeholder="Escaneá el código de barras o el SKU y Enter" autocomplete="off" autofocus>
                    </div>
                </div>
                <div class="col-lg-3">
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" role="switch" id="sumarUno" checked>
                        <label class="form-check-label small" for="sumarUno">Cada lectura suma 1</label>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalAgregar"><i class="bi bi-plus-lg"></i> Apareció algo que no está en la lista</button>
                </div>
                <div class="col-12 small text-muted" id="mensajeEscaner"></div>
            </div>
        </div>
    @endif

    {{-- Filtros --}}
    <div class="card card-body mb-3">
        <div class="row g-2">
            <div class="col-md-5"><input type="search" id="filtroTexto" class="form-control" placeholder="Filtrar por producto, SKU o lote"></div>
            @if ($ubicaciones->isNotEmpty())
                <div class="col-md-3">
                    <select id="filtroUbicacion" class="form-select">
                        <option value="">Todas las ubicaciones</option>
                        <option value="0">Sin ubicar</option>
                        @foreach ($ubicaciones as $u)
                            <option value="{{ $u->id }}">{{ $u->etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="col-md-4">
                <select id="filtroEstado" class="form-select">
                    <option value="">Todos los renglones</option>
                    <option value="pendiente">Solo pendientes de contar</option>
                    <option value="contado">Solo contados</option>
                    @if ($muestraTeorico)<option value="diferencia">Solo con diferencia</option>@endif
                </select>
            </div>
        </div>
    </div>

    <form method="POST" action="{{ route('inventario.tomas.conteo', $toma) }}" id="formConteo">
        @csrf
        <div class="card">
            <div class="table-responsive">
                <table class="table align-middle mb-0" id="tablaConteo">
                    <thead class="table-light">
                    <tr>
                        <th>Producto</th>
                        @if ($toma->deposito?->usa_ubicaciones)<th>Ubicación</th>@endif
                        <th>Lote</th>
                        @if ($muestraTeorico)<th class="text-end">Sistema</th>@endif
                        <th class="text-end" style="width: 170px;">Contado</th>
                        @if ($muestraTeorico)<th class="text-end">Diferencia</th>@endif
                        @unless ($enCurso)<th class="text-end">Valor</th>@endunless
                        <th class="small">Contó</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($items as $item)
                        @php
                            $p = $item->producto;
                            $u = $p?->unidad?->abreviatura;
                            $codigos = collect([$p?->sku])->merge($p?->codigos?->pluck('codigo') ?? [])->filter()->map(fn ($c) => mb_strtolower($c))->implode('|');
                            $diferencia = $item->diferencia;
                        @endphp
                        <tr id="item-{{ $item->id }}"
                            data-id="{{ $item->id }}"
                            data-producto="{{ $item->producto_id }}"
                            data-codigos="{{ $codigos }}"
                            data-texto="{{ mb_strtolower(($p?->nombre ?? '') . ' ' . ($p?->sku ?? '') . ' ' . ($item->lote_texto ?? '')) }}"
                            data-ubicacion="{{ $item->ubicacion_id ?? 0 }}"
                            data-teorico="{{ (float) $item->teorico }}"
                            data-series="{{ $p?->usa_series ? 1 : 0 }}"
                            data-contado="{{ $item->contado === null ? '' : (float) $item->contado }}">
                            <td>
                                <div class="fw-semibold">{{ $p?->nombre }}
                                    @if ($item->agregado)<span class="badge text-bg-light border">agregado</span>@endif
                                </div>
                                <div class="small text-muted"><code>{{ $p?->sku }}</code> · {{ $u }}</div>
                            </td>
                            @if ($toma->deposito?->usa_ubicaciones)<td class="small">{{ $item->ubicacion?->codigo ?? 'Sin ubicar' }}</td>@endif
                            <td class="small">
                                {{ $item->lote_texto ?? '—' }}
                                @if ($item->lote?->fecha_vencimiento)<div class="text-muted">vence {{ $item->lote->fecha_vencimiento->format('d/m/Y') }}</div>@endif
                            </td>
                            @if ($muestraTeorico)
                                <td class="text-end text-muted">
                                    {{ Formato::cantidad($item->teorico) }}
                                    @if ($p?->usa_series && $item->series_teoricas)
                                        <div class="small font-monospace text-break" style="max-width: 220px; margin-left: auto;">{{ implode(', ', $item->series_teoricas) }}</div>
                                    @endif
                                </td>
                            @endif
                            <td class="text-end">
                                @if ($enCurso)
                                    @if ($p?->usa_series)
                                        <textarea class="form-control form-control-sm font-monospace input-series" rows="2" data-name="conteos[{{ $item->id }}][series]" placeholder="Escaneá las series">{{ implode("\n", $item->series_contadas ?? []) }}</textarea>
                                        <div class="small text-muted"><span class="cuenta-series">{{ count($item->series_contadas ?? []) }}</span> unid.</div>
                                    @else
                                        <input type="text" inputmode="decimal" class="form-control form-control-sm input-contado" data-name="conteos[{{ $item->id }}][contado]"
                                               value="{{ $item->contado === null ? '' : str_replace('.', ',', (string) (float) $item->contado) }}" placeholder="—">
                                    @endif
                                @else
                                    <span class="fw-semibold">{{ $item->contado === null ? '—' : Formato::cantidad($item->contado) }}</span>
                                    @if ($p?->usa_series && $item->series_contadas)
                                        <div class="small font-monospace text-break">{{ implode(', ', $item->series_contadas) }}</div>
                                    @endif
                                @endif
                            </td>
                            @if ($muestraTeorico)
                                <td class="text-end fw-semibold celda-diferencia {{ $diferencia === null ? '' : ($diferencia < 0 ? 'text-danger' : ($diferencia > 0 ? 'text-success' : 'text-muted')) }}">
                                    {{ $diferencia === null ? '' : Formato::cantidadConSigno($diferencia) }}
                                </td>
                            @endif
                            @unless ($enCurso)
                                <td class="text-end small {{ ($item->valor_diferencia ?? 0) < 0 ? 'text-danger' : '' }}">{{ $item->valor_diferencia ? Formato::moneda($item->valor_diferencia) : '' }}</td>
                            @endunless
                            <td class="small text-muted text-nowrap">
                                @if ($item->contado_at)
                                    {{ $item->usuarioConteo?->name }}<div>{{ $item->contado_at->format('d/m H:i') }}</div>
                                    @if ($enCurso)
                                        <button type="button" class="btn btn-link btn-sm p-0 text-danger btn-limpiar" title="Borrar este conteo">borrar</button>
                                        <input type="hidden" data-name="conteos[{{ $item->id }}][limpiar]" value="1" class="input-limpiar">
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-5">No hay nada en el alcance de esta toma. Agregá lo que encuentres con el botón "Apareció algo".</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($enCurso)
            <div class="barra-guardar bg-white border-top py-2 mt-3 d-flex align-items-center gap-2">
                <button class="btn btn-stk" id="btnGuardar" disabled><i class="bi bi-save"></i> Guardar conteo</button>
                <span class="small text-muted" id="pendientesGuardar">Sin cambios.</span>
            </div>
        @endif
    </form>

    @if ($enCurso)
        {{-- Agregar renglón --}}
        <div class="modal fade" id="modalAgregar" tabindex="-1">
            <div class="modal-dialog">
                <form method="POST" action="{{ route('inventario.tomas.agregar', $toma) }}" class="modal-content" id="formAgregar">
                    @csrf
                    <input type="hidden" name="producto_id" id="agProducto">
                    <div class="modal-header">
                        <h2 class="modal-title h5">Agregar lo que apareció</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Producto *</label>
                        <input type="search" id="agBuscar" class="form-control" placeholder="Nombre, SKU o código" autocomplete="off">
                        <div class="list-group mt-1 d-none" id="agResultados"></div>
                        <div class="alert alert-light border small mt-2 d-none" id="agElegido"></div>

                        @if ($ubicaciones->isNotEmpty())
                            <label class="form-label mt-3">Ubicación</label>
                            <select name="ubicacion_id" class="form-select">
                                @unless ($toma->ubicacion_id)<option value="">Sin ubicar</option>@endunless
                                @foreach ($ubicaciones as $u)
                                    <option value="{{ $u->id }}" @selected($toma->ubicacion_id === $u->id)>{{ $u->etiqueta }}</option>
                                @endforeach
                            </select>
                        @endif

                        <div class="row g-2 mt-1 d-none" id="agLote">
                            <div class="col-7">
                                <label class="form-label">Lote *</label>
                                <input type="text" name="lote_codigo" class="form-control text-uppercase" list="agLotesConocidos" maxlength="60">
                                <datalist id="agLotesConocidos"></datalist>
                            </div>
                            <div class="col-5">
                                <label class="form-label">Vence</label>
                                <input type="date" name="lote_vencimiento" class="form-control">
                            </div>
                        </div>

                        <div class="mt-3" id="agCantidad">
                            <label class="form-label">Cantidad contada *</label>
                            <input type="text" inputmode="decimal" name="contado" class="form-control">
                        </div>
                        <div class="mt-3 d-none" id="agSeries">
                            <label class="form-label">Números de serie *</label>
                            <textarea name="series" class="form-control font-monospace" rows="3" placeholder="Uno por línea"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                        <button class="btn btn-stk" id="agGuardar" disabled>Agregar</button>
                    </div>
                </form>
            </div>
        </div>

        @if ($puedeAdministrar)
            <div class="modal fade" id="modalCerrar" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('inventario.tomas.cerrar', $toma) }}" class="modal-content">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title h5">Cerrar la toma y ajustar el stock</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <ul class="small">
                                <li>Contados: <strong>{{ $resumen['contados'] }}</strong> de {{ $resumen['total'] }} renglones.</li>
                                <li>Con diferencia: <strong>{{ $resumen['con_diferencia'] }}</strong>
                                    (faltante {{ Formato::moneda($resumen['faltante']) }} · sobrante {{ Formato::moneda($resumen['sobrante']) }}).</li>
                                <li>Se genera <strong>un solo ajuste</strong> con motivo "Diferencia de inventario".</li>
                            </ul>
                            @if ($resumen['pendientes'])
                                <label class="form-label">¿Qué hacemos con los {{ $resumen['pendientes'] }} renglones sin contar?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="no_contados" id="ncIgnorar" value="ignorar" checked>
                                    <label class="form-check-label" for="ncIgnorar">No tocarlos (quedan como dice el sistema)</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="no_contados" id="ncCero" value="cero">
                                    <label class="form-check-label" for="ncCero">Darlos como <strong>faltante</strong> (no están físicamente)</label>
                                </div>
                            @else
                                <input type="hidden" name="no_contados" value="ignorar">
                            @endif
                            <label class="form-label mt-3">Observaciones</label>
                            <input type="text" name="observaciones" class="form-control" maxlength="2000">
                            <p class="small text-muted mt-3 mb-0">Guardá el conteo antes de cerrar: lo que no esté guardado no se tiene en cuenta.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                            <button class="btn btn-success" onclick="return confirm('¿Cerrar la toma y ajustar el stock? No se puede volver a abrir.')">Cerrar y ajustar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="modal fade" id="modalCancelar" tabindex="-1">
                <div class="modal-dialog">
                    <form method="POST" action="{{ route('inventario.tomas.cancelar', $toma) }}" class="modal-content">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title h5">Cancelar la toma</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small">No se ajusta nada{{ $toma->congela ? ' y el depósito vuelve a aceptar movimientos' : '' }}. Lo contado queda guardado como referencia.</p>
                            <label class="form-label">Motivo *</label>
                            <input type="text" name="motivo" class="form-control" maxlength="255" required>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Volver</button>
                            <button class="btn btn-danger">Cancelar toma</button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    @endif
@endsection

@push('scripts')
<script>
(() => {
    const filas = Array.from(document.querySelectorAll('#tablaConteo tbody tr[data-id]'));
    const MUESTRA_TEORICO = @json($muestraTeorico);
    const EN_CURSO = @json($enCurso);
    const total = filas.length;
    const fmt = (n) => Number(n).toLocaleString('es-AR', { maximumFractionDigits: 4 });
    const enInput = (n) => String(Math.round(Number(n) * 10000) / 10000).replace('.', ',');
    // Acepta "2,5" o "2.5"; si trae coma, los puntos son separadores de miles
    const numero = (v) => {
        let t = String(v ?? '').trim();
        if (t === '') return null;
        if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
        return Number(t);
    };
    let filaActiva = null;

    /* ---------- Filtros ---------- */
    const fTexto = document.getElementById('filtroTexto');
    const fUbic = document.getElementById('filtroUbicacion');
    const fEstado = document.getElementById('filtroEstado');

    function aplicarFiltros() {
        const t = fTexto.value.trim().toLowerCase();
        const u = fUbic ? fUbic.value : '';
        const e = fEstado.value;
        filas.forEach((f) => {
            const contado = f.dataset.contado !== '';
            const dif = contado && Math.abs(Number(f.dataset.contado) - Number(f.dataset.teorico)) > 0.00005;
            let ver = (!t || f.dataset.texto.includes(t) || f.dataset.codigos.split('|').includes(t))
                && (u === '' || f.dataset.ubicacion === u)
                && (e === '' || (e === 'pendiente' && !contado) || (e === 'contado' && contado) || (e === 'diferencia' && dif));
            f.classList.toggle('d-none', !ver);
        });
    }
    [fTexto, fUbic, fEstado].forEach((el) => el && el.addEventListener('input', aplicarFiltros));

    if (!EN_CURSO) return;

    /* ---------- Cambios pendientes ---------- */
    const btnGuardar = document.getElementById('btnGuardar');
    const avisoGuardar = document.getElementById('pendientesGuardar');
    let sinGuardar = 0;

    function marcar(fila) {
        if (!fila.classList.contains('cambiado')) { fila.classList.add('cambiado'); sinGuardar++; }
        fila.querySelectorAll('[data-name]:not(.input-limpiar)').forEach((el) => { el.name = el.dataset.name; });
        fila.querySelector('.input-limpiar')?.removeAttribute('name');
        btnGuardar.disabled = false;
        avisoGuardar.textContent = `${sinGuardar} renglón(es) sin guardar.`;
        actualizarFila(fila);
    }

    function actualizarFila(fila) {
        let contado;
        const series = fila.querySelector('.input-series');
        if (series) {
            const lista = [...new Set(series.value.split(/[\r\n,;]+/).map((s) => s.trim()).filter(Boolean))];
            fila.querySelector('.cuenta-series').textContent = lista.length;
            contado = lista.length;
        } else {
            contado = numero(fila.querySelector('.input-contado').value);
        }
        fila.dataset.contado = contado === null || Number.isNaN(contado) ? '' : contado;

        const celda = fila.querySelector('.celda-diferencia');
        if (celda && MUESTRA_TEORICO) {
            if (fila.dataset.contado === '') { celda.textContent = ''; return; }
            const d = Math.round((Number(fila.dataset.contado) - Number(fila.dataset.teorico)) * 10000) / 10000;
            celda.textContent = (d > 0 ? '+' : '') + fmt(d);
            celda.className = 'text-end fw-semibold celda-diferencia ' + (d < 0 ? 'text-danger' : (d > 0 ? 'text-success' : 'text-muted'));
        }
    }

    filas.forEach((f) => {
        f.querySelectorAll('.input-contado, .input-series').forEach((el) => {
            el.addEventListener('input', () => marcar(f));
            el.addEventListener('focus', () => { filaActiva = f; });
        });
        const limpiar = f.querySelector('.btn-limpiar');
        if (limpiar) limpiar.addEventListener('click', () => {
            const campo = f.querySelector('.input-contado, .input-series');
            campo.value = '';
            f.querySelectorAll('[data-name]').forEach((el) => { el.removeAttribute('name'); });
            f.querySelector('.input-limpiar').name = f.querySelector('.input-limpiar').dataset.name;
            if (!f.classList.contains('cambiado')) { f.classList.add('cambiado'); sinGuardar++; }
            btnGuardar.disabled = false;
            avisoGuardar.textContent = `${sinGuardar} renglón(es) sin guardar.`;
            f.dataset.contado = '';
            const celda = f.querySelector('.celda-diferencia'); if (celda) celda.textContent = '';
        });
    });

    document.getElementById('formConteo').addEventListener('submit', () => { sinGuardar = 0; btnGuardar.disabled = true; });
    window.addEventListener('beforeunload', (e) => { if (sinGuardar > 0) { e.preventDefault(); e.returnValue = ''; } });

    /* ---------- Lector de códigos ---------- */
    const escaner = document.getElementById('escaner');
    const mensaje = document.getElementById('mensajeEscaner');
    const sumarUno = document.getElementById('sumarUno');

    function resaltar(fila) {
        fila.classList.add('resaltado');
        fila.scrollIntoView({ block: 'center', behavior: 'smooth' });
        setTimeout(() => fila.classList.remove('resaltado'), 700);
    }

    escaner.addEventListener('keydown', async (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        const codigo = escaner.value.trim();
        escaner.value = '';
        if (!codigo) return;
        const c = codigo.toLowerCase();

        let candidatas = filas.filter((f) => f.dataset.codigos.split('|').includes(c));

        // Una serie leída mientras se está contando un producto con series
        if (!candidatas.length && filaActiva && filaActiva.dataset.series === '1') {
            const ta = filaActiva.querySelector('.input-series');
            const lista = ta.value.split(/[\r\n,;]+/).map((s) => s.trim()).filter(Boolean);
            if (lista.includes(codigo)) { mensaje.textContent = `La serie ${codigo} ya estaba cargada.`; return; }
            ta.value = [...lista, codigo].join('\n');
            marcar(filaActiva); resaltar(filaActiva);
            mensaje.textContent = `Serie ${codigo} sumada.`;
            return;
        }

        if (candidatas.length) {
            const u = fUbic ? fUbic.value : '';
            if (u !== '') candidatas = candidatas.filter((f) => f.dataset.ubicacion === u).concat(candidatas.filter((f) => f.dataset.ubicacion !== u));
            const fila = candidatas.find((f) => f.dataset.contado === '') || candidatas[0];
            fila.classList.remove('d-none');
            filaActiva = fila;
            resaltar(fila);

            if (fila.dataset.series === '1') {
                fila.querySelector('.input-series').focus();
                mensaje.textContent = 'Producto con número de serie: escaneá ahora las series.';
                escaner.focus();
                return;
            }

            const input = fila.querySelector('.input-contado');
            if (sumarUno.checked) {
                input.value = enInput((numero(input.value) || 0) + 1);
                marcar(fila);
                mensaje.textContent = `${fila.querySelector('.fw-semibold').textContent.trim()}: ${input.value}` + (candidatas.length > 1 ? ' (tiene varios renglones: se sumó al primero pendiente)' : '');
                escaner.focus();
            } else {
                input.focus(); input.select();
            }
            return;
        }

        // No está en la lista: ¿existe el producto?
        try {
            const r = await fetch(@json(route('catalogo.productos.buscar')) + '?stockeables=1&q=' + encodeURIComponent(codigo), { headers: { Accept: 'application/json' } });
            const d = await r.json();
            if (d.exacto && d.resultados.length) {
                mensaje.innerHTML = `<span class="text-warning-emphasis">${d.resultados[0].nombre} no está en la lista.</span>`;
                abrirAgregar(d.resultados[0]);
            } else {
                mensaje.innerHTML = `<span class="text-danger">No se encontró el código ${codigo}.</span>`;
            }
        } catch (_) { mensaje.textContent = 'No se pudo buscar el código.'; }
    });

    /* ---------- Agregar renglón ---------- */
    const modal = document.getElementById('modalAgregar');
    const agBuscar = document.getElementById('agBuscar');
    const agResultados = document.getElementById('agResultados');
    const agElegido = document.getElementById('agElegido');
    let temporizador;

    function elegir(p) {
        document.getElementById('agProducto').value = p.id;
        agElegido.innerHTML = `<strong>${p.nombre}</strong> <code>${p.sku}</code> · ${p.unidad ?? ''}`;
        agElegido.classList.remove('d-none');
        agResultados.classList.add('d-none');
        agBuscar.value = '';
        document.getElementById('agLote').classList.toggle('d-none', !p.usa_lotes);
        document.getElementById('agSeries').classList.toggle('d-none', !p.usa_series);
        document.getElementById('agCantidad').classList.toggle('d-none', !!p.usa_series);
        document.getElementById('agLotesConocidos').innerHTML = (p.lotes_conocidos || []).map((l) => `<option value="${l.codigo}">${l.vencimiento ? 'vence ' + l.vencimiento : ''}</option>`).join('');
        document.getElementById('agGuardar').disabled = false;
    }

    function abrirAgregar(p) {
        bootstrap.Modal.getOrCreateInstance(modal).show();
        if (p) elegir(p);
    }

    agBuscar.addEventListener('input', () => {
        clearTimeout(temporizador);
        const q = agBuscar.value.trim();
        if (q.length < 2) { agResultados.classList.add('d-none'); return; }
        temporizador = setTimeout(async () => {
            const r = await fetch(@json(route('catalogo.productos.buscar')) + '?stockeables=1&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
            const d = await r.json();
            if (d.exacto && d.resultados.length === 1) { elegir(d.resultados[0]); return; }
            agResultados.innerHTML = '';
            d.resultados.forEach((p) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'list-group-item list-group-item-action small';
                b.innerHTML = `<strong>${p.nombre}</strong> <code>${p.sku}</code>`;
                b.addEventListener('click', () => elegir(p));
                agResultados.appendChild(b);
            });
            agResultados.classList.toggle('d-none', !d.resultados.length);
        }, 250);
    });
    modal.addEventListener('shown.bs.modal', () => { if (!document.getElementById('agProducto').value) agBuscar.focus(); });
})();
</script>
@endpush
