{{-- resources/views/inventario/transferencia-recibir.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Recibir transferencia N° ' . $transferencia->numero_texto)

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('inventario.transferencias.show', $transferencia) }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-box-arrow-in-down"></i> Recibir transferencia N° {{ $transferencia->numero_texto }}</h1>
            <span class="text-muted small">
                De {{ $transferencia->origen?->nombre }} a <strong>{{ $transferencia->destino?->nombre }}</strong>
                · enviada el {{ $transferencia->enviada_at->format('d/m/Y H:i') }} por {{ $transferencia->enviadaPor?->name ?? 'el sistema' }}
            </span>
        </div>
    </div>

    <form method="POST" action="{{ route('inventario.transferencias.recibir', $transferencia) }}" id="formRecepcion">
        @csrf

        <div class="card mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Contá lo que llegó</span>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="llegoTodo"><i class="bi bi-check2-all"></i> Llegó todo</button>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Producto</th>
                        <th class="text-end" style="width: 130px;">Enviado</th>
                        <th style="width: 170px;">Recibido *</th>
                        <th class="text-end" style="width: 120px;">Diferencia</th>
                        @if ($ubicaciones->isNotEmpty())
                            <th style="width: 210px;">Ubicar en</th>
                        @endif
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($transferencia->items as $item)
                        @php($unidad = $item->producto?->unidad?->abreviatura)
                        @php($conSeries = ! empty($item->series))
                        <tr class="fila" data-enviado="{{ (float) $item->cantidad_enviada }}" data-series="{{ $conSeries ? 1 : 0 }}">
                            <td>
                                <div class="fw-semibold">{{ $item->producto?->nombre }}</div>
                                <div class="small text-muted"><code>{{ $item->producto?->sku }}</code></div>
                                @if ($item->lote)
                                    <div class="small"><span class="badge text-bg-light border">Lote {{ $item->lote->codigo }}</span>
                                        @if ($item->lote->fecha_vencimiento)<span class="text-muted">vence {{ $item->lote->fecha_vencimiento->format('d/m/Y') }}</span>@endif
                                    </div>
                                @endif
                                @if ($conSeries)
                                    @php($marcadas = old("recepcion.{$item->id}.series_recibidas", $item->series))
                                    <div class="small mt-1">
                                        <span class="text-muted">Marcá las series que llegaron:</span>
                                        <div class="d-flex flex-wrap gap-2 mt-1">
                                            @foreach ($item->series as $numero)
                                                <label class="form-check form-check-inline border rounded px-2 py-1 m-0">
                                                    <input class="form-check-input serie ms-0 me-1" type="checkbox" name="recepcion[{{ $item->id }}][series_recibidas][]"
                                                           value="{{ $numero }}" @checked(in_array($numero, $marcadas, true))>
                                                    <span class="font-monospace">{{ $numero }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </td>
                            <td class="text-end">{{ Formato::cantidad($item->cantidad_enviada) }} {{ $unidad }}</td>
                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" name="recepcion[{{ $item->id }}][cantidad_recibida]" class="form-control recibido"
                                           min="0" max="{{ (float) $item->cantidad_enviada }}"
                                           step="{{ $item->producto?->unidad?->permite_decimales && ! $conSeries ? 'any' : '1' }}"
                                           @if ($conSeries) readonly tabindex="-1" @else required @endif
                                           value="{{ old("recepcion.{$item->id}.cantidad_recibida") }}">
                                    <span class="input-group-text">{{ $unidad }}</span>
                                </div>
                            </td>
                            <td class="text-end fw-semibold diferencia">—</td>
                            @if ($ubicaciones->isNotEmpty())
                                <td>
                                    <select name="recepcion[{{ $item->id }}][ubicacion_destino_id]" class="form-select form-select-sm">
                                        <option value="">Sin ubicar</option>
                                        @foreach ($ubicaciones as $u)
                                            <option value="{{ $u->id }}" @selected((string) old("recepcion.{$item->id}.ubicacion_destino_id") === (string) $u->id)>{{ $u->etiqueta }}</option>
                                        @endforeach
                                    </select>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-3 d-none" id="bloqueDiferencias">
            <div class="card-body">
                <div class="fw-semibold text-danger mb-2"><i class="bi bi-exclamation-triangle"></i> Hay mercadería que no llegó. ¿Qué hacemos con eso?</div>
                @foreach ($opcionesDiferencias as $valor => $texto)
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="destino_diferencias" id="dif_{{ $valor }}" value="{{ $valor }}"
                               @checked(old('destino_diferencias', 'faltante') === $valor)>
                        <label class="form-check-label" for="dif_{{ $valor }}">{{ $texto }}</label>
                    </div>
                @endforeach
                <div class="form-text">
                    "Faltante" la da de baja con el motivo <em>Faltante en transferencia</em>. "Devolver" la vuelve al stock de {{ $transferencia->origen?->nombre }}
                    (por ejemplo, si quedó en el camión o no se cargó).
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <label class="form-label">Observaciones de la recepción</label>
                <input type="text" name="observaciones_recepcion" class="form-control" maxlength="2000" value="{{ old('observaciones_recepcion') }}">
            </div>
        </div>

        <button class="btn btn-success" id="btnRecibir"><i class="bi bi-check-lg"></i> Confirmar recepción</button>
        <a href="{{ route('inventario.transferencias.show', $transferencia) }}" class="btn btn-light">Cancelar</a>
    </form>
@endsection

@push('scripts')
<script>
(() => {
    const formato = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 4 });
    const filas = document.querySelectorAll('tr.fila');

    function contarSeries(fila) {
        if (fila.dataset.series !== '1') return;
        fila.querySelector('.recibido').value = fila.querySelectorAll('input.serie:checked').length;
    }

    function recalcular() {
        let hayFaltantes = false;

        filas.forEach((fila) => {
            contarSeries(fila);
            const enviado = parseFloat(fila.dataset.enviado) || 0;
            const campo = fila.querySelector('.recibido');
            const celda = fila.querySelector('.diferencia');

            if (campo.value === '') {
                celda.textContent = '—';
                celda.className = 'text-end fw-semibold diferencia text-muted';
                return;
            }

            const diferencia = enviado - (parseFloat(campo.value) || 0);
            if (diferencia > 0.00005) hayFaltantes = true;

            celda.textContent = diferencia > 0.00005 ? `−${formato.format(diferencia)}` : (diferencia < -0.00005 ? 'Supera lo enviado' : 'OK');
            celda.className = `text-end fw-semibold diferencia ${diferencia > 0.00005 || diferencia < -0.00005 ? 'text-danger' : 'text-success'}`;
        });

        document.getElementById('bloqueDiferencias').classList.toggle('d-none', !hayFaltantes);
    }

    document.getElementById('llegoTodo').addEventListener('click', () => {
        filas.forEach((fila) => {
            fila.querySelectorAll('input.serie').forEach((c) => { c.checked = true; });
            fila.querySelector('.recibido').value = fila.dataset.enviado;
        });
        recalcular();
    });

    filas.forEach((fila) => {
        fila.querySelector('.recibido').addEventListener('input', recalcular);
        fila.querySelectorAll('input.serie').forEach((c) => c.addEventListener('change', recalcular));
    });
    document.getElementById('formRecepcion').addEventListener('submit', () => { document.getElementById('btnRecibir').disabled = true; });

    recalcular();
    filas[0]?.querySelector('.recibido').focus();
})();
</script>
@endpush
