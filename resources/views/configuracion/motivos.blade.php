{{-- resources/views/configuracion/motivos.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Motivos de movimientos')

@section('contenido')
    @php
        $rutaAlta = route('configuracion.motivos.store');
        $iconos = ['ingreso' => 'bi-box-arrow-in-down text-success', 'egreso' => 'bi-box-arrow-up text-danger', 'ajuste' => 'bi-sliders text-warning'];
    @endphp

    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="h4 mb-0">Motivos de movimientos</h1>
        <button type="button" class="btn btn-stk" onclick="abrirMotivo()"><i class="bi bi-plus-lg"></i> Nuevo motivo</button>
    </div>
    <p class="text-muted small mb-4">
        Cada ingreso, egreso o ajuste se registra con un motivo. Así después podés saber cuánto se perdió por roturas,
        cuánto se consumió internamente, etc.
    </p>

    <div class="row g-3">
        @foreach ($tipos as $tipo => $titulo)
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                        <span><i class="bi {{ $iconos[$tipo] }}"></i> {{ $titulo }}</span>
                        <button type="button" class="btn btn-sm btn-light" onclick="abrirMotivo(null, '{{ $tipo }}')" title="Agregar"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse ($motivos->get($tipo, collect()) as $m)
                            <li class="list-group-item d-flex justify-content-between align-items-center gap-2 {{ $m->activo ? '' : 'text-muted' }}">
                                <div>
                                    <div class="{{ $m->activo ? 'fw-semibold' : '' }}">{{ $m->nombre }}</div>
                                    <div class="small">
                                        @if ($m->pide_costo)<span class="badge text-bg-light border">Pide costo</span>@endif
                                        @if ($m->requiere_observacion)<span class="badge text-bg-light border">Obs. obligatoria</span>@endif
                                        @unless ($m->activo)<span class="badge text-bg-secondary">Inactivo</span>@endunless
                                        @if ($m->movimientos_count)<span class="text-muted">{{ $m->movimientos_count }} usos</span>@endif
                                    </div>
                                </div>
                                <div class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Editar"
                                            data-motivo="{{ json_encode([
                                                'url' => route('configuracion.motivos.update', $m),
                                                'tipo' => $m->tipo,
                                                'nombre' => $m->nombre,
                                                'orden' => $m->orden,
                                                'pide_costo' => $m->pide_costo,
                                                'requiere_observacion' => $m->requiere_observacion,
                                                'activo' => $m->activo,
                                                'usado' => $m->movimientos_count > 0,
                                            ]) }}"
                                            onclick="abrirMotivo(JSON.parse(this.dataset.motivo))">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    @unless ($m->movimientos_count)
                                        <form method="POST" action="{{ route('configuracion.motivos.destroy', $m) }}" class="d-inline"
                                              onsubmit="return confirm('¿Eliminar el motivo {{ addslashes($m->nombre) }}?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                        </form>
                                    @endunless
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item text-muted small">Sin motivos.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endforeach
    </div>

    <div class="modal fade" id="modalMotivo" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="formMotivo" action="{{ old('_accion', $rutaAlta) }}" class="modal-content">
                @csrf
                <input type="hidden" name="_method" id="motMetodo" value="{{ old('_method', 'POST') }}">
                <input type="hidden" name="_accion" id="motAccion" value="{{ old('_accion', $rutaAlta) }}">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="motTitulo">{{ old('_method') === 'PUT' ? 'Editar motivo' : 'Nuevo motivo' }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Tipo *</label>
                        <select name="tipo" id="motTipo" class="form-select" required onchange="mostrarCosto()">
                            @foreach ($tipos as $valor => $texto)
                                <option value="{{ $valor }}" @selected(old('tipo') === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <input type="hidden" id="motTipoFijo" disabled>
                        <div class="form-text d-none" id="motTipoAviso">Ya se usó: el tipo no se puede cambiar.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Orden</label>
                        <input type="number" name="orden" id="motOrden" class="form-control" min="0" max="999" value="{{ old('orden', 0) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nombre *</label>
                        <input type="text" name="nombre" id="motNombre" class="form-control" maxlength="80" value="{{ old('nombre') }}" required>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch mb-2" id="grupoCosto">
                            <input class="form-check-input" type="checkbox" role="switch" name="pide_costo" id="motCosto" value="1" @checked(old('pide_costo'))>
                            <label class="form-check-label" for="motCosto">Pide costo (recalcula el costo promedio, como una compra)</label>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="requiere_observacion" id="motObs" value="1" @checked(old('requiere_observacion'))>
                            <label class="form-check-label" for="motObs">Las observaciones son obligatorias</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="activo" id="motActivo" value="1"
                                   @checked($errors->any() ? old('activo') : true)>
                            <label class="form-check-label" for="motActivo">Activo</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Guardar</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const rutaAltaMotivo = @json($rutaAlta);

    function mostrarCosto() {
        document.getElementById('grupoCosto').classList.toggle('d-none', document.getElementById('motTipo').value !== 'ingreso');
    }

    function abrirMotivo(datos = null, tipo = 'ingreso') {
        const url = datos ? datos.url : rutaAltaMotivo;
        const selectTipo = document.getElementById('motTipo');
        const tipoFijo = document.getElementById('motTipoFijo');

        document.getElementById('formMotivo').action = url;
        document.getElementById('motAccion').value = url;
        document.getElementById('motMetodo').value = datos ? 'PUT' : 'POST';
        document.getElementById('motTitulo').textContent = datos ? 'Editar motivo' : 'Nuevo motivo';

        selectTipo.value = datos?.tipo ?? tipo;
        const bloqueado = !!datos?.usado;
        selectTipo.disabled = bloqueado;
        tipoFijo.disabled = !bloqueado;
        tipoFijo.name = bloqueado ? 'tipo' : '';
        tipoFijo.value = selectTipo.value;
        document.getElementById('motTipoAviso').classList.toggle('d-none', !bloqueado);

        document.getElementById('motNombre').value = datos?.nombre ?? '';
        document.getElementById('motOrden').value = datos?.orden ?? 0;
        document.getElementById('motCosto').checked = !!datos?.pide_costo;
        document.getElementById('motObs').checked = !!datos?.requiere_observacion;
        document.getElementById('motActivo').checked = datos ? !!datos.activo : true;

        mostrarCosto();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMotivo')).show();
        setTimeout(() => document.getElementById('motNombre').focus(), 300);
    }

    mostrarCosto();

    @if ($errors->any())
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMotivo')).show();
    @endif
</script>
@endpush
