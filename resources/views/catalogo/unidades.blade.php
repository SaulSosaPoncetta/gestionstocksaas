{{-- resources/views/catalogo/unidades.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Unidades de medida')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h1 class="h4 mb-0">Unidades de medida</h1>
        <button type="button" class="btn btn-stk" onclick="abrirUnidad()">
            <i class="bi bi-plus-lg"></i> Nueva unidad
        </button>
    </div>
    <p class="text-muted small mb-4">
        Las unidades que dependen de una base se convierten solas (por ejemplo, 1.500 g = 1,5 kg).
        Las presentaciones de cada producto, como "caja x 12", se cargan en la ficha del producto.
    </p>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Abrev.</th>
                    <th>Tipo</th>
                    <th>Equivalencia</th>
                    <th>Decimales</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($unidades as $u)
                    <tr class="{{ $u->activa ? '' : 'text-muted' }}">
                        <td class="{{ $u->esBase() ? 'fw-semibold' : '' }}">
                            @unless ($u->esBase())
                                <i class="bi bi-arrow-return-right text-muted me-1"></i>
                            @endunless
                            {{ $u->nombre }}
                        </td>
                        <td><code>{{ $u->abreviatura }}</code></td>
                        <td>{{ $u->tipo_nombre }}</td>
                        <td class="small">
                            @if ($u->esBase())
                                <span class="badge text-bg-light border">Unidad base</span>
                            @else
                                1 {{ $u->abreviatura }} = {{ $u->factor_texto }} {{ $u->base?->abreviatura }}
                            @endif
                        </td>
                        <td>{!! $u->permite_decimales ? '<i class="bi bi-check-lg text-success"></i>' : '<span class="text-muted">—</span>' !!}</td>
                        <td>
                            @if ($u->activa)
                                <span class="badge text-bg-success">Activa</span>
                            @else
                                <span class="badge text-bg-secondary">Inactiva</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Editar"
                                    data-unidad="{{ json_encode([
                                        'url' => route('catalogo.unidades.update', $u),
                                        'id' => $u->id,
                                        'nombre' => $u->nombre,
                                        'abreviatura' => $u->abreviatura,
                                        'tipo' => $u->tipo,
                                        'unidad_base_id' => $u->unidad_base_id,
                                        'factor' => (float) $u->factor,
                                        'permite_decimales' => $u->permite_decimales,
                                        'activa' => $u->activa,
                                    ]) }}"
                                    onclick="abrirUnidad(JSON.parse(this.dataset.unidad))">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('catalogo.unidades.destroy', $u) }}" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar la unidad {{ addslashes($u->nombre) }}?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Eliminar" @disabled($u->derivadas_count > 0)>
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">Todavía no hay unidades de medida.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal alta / edición --}}
    @php($rutaAlta = route('catalogo.unidades.store'))
    <div class="modal fade" id="modalUnidad" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="formUnidad" action="{{ old('_accion', $rutaAlta) }}" class="modal-content">
                @csrf
                <input type="hidden" name="_method" id="uniMetodo" value="{{ old('_method', 'POST') }}">
                <input type="hidden" name="_accion" id="uniAccion" value="{{ old('_accion', $rutaAlta) }}">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="uniTitulo">{{ old('_method') === 'PUT' ? 'Editar unidad' : 'Nueva unidad' }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-8">
                        <label class="form-label">Nombre *</label>
                        <input type="text" name="nombre" id="uniNombre" class="form-control" maxlength="50" value="{{ old('nombre') }}" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Abreviatura *</label>
                        <input type="text" name="abreviatura" id="uniAbrev" class="form-control" maxlength="10" value="{{ old('abreviatura') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tipo *</label>
                        <select name="tipo" id="uniTipo" class="form-select" required onchange="filtrarBases()">
                            @foreach ($tipos as $valor => $texto)
                                <option value="{{ $valor }}" @selected(old('tipo', 'unidad') === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Depende de</label>
                        <select name="unidad_base_id" id="uniBase" class="form-select" onchange="mostrarFactor()">
                            <option value="">— Es unidad base —</option>
                            @foreach ($bases as $b)
                                <option value="{{ $b->id }}" data-tipo="{{ $b->tipo }}" data-abrev="{{ $b->abreviatura }}"
                                        @selected((string) old('unidad_base_id') === (string) $b->id)>
                                    {{ $b->nombre }} ({{ $b->abreviatura }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12" id="uniFactorGrupo">
                        <label class="form-label">Equivalencia *</label>
                        <div class="input-group">
                            <span class="input-group-text">1 <span id="uniAbrevTexto" class="ms-1">unidad</span> =</span>
                            <input type="number" name="factor" id="uniFactor" class="form-control" step="any" min="0.000001" value="{{ old('factor') }}">
                            <span class="input-group-text" id="uniBaseTexto"></span>
                        </div>
                        <div class="form-text">Ej.: 1 g = 0,001 kg · 1 doc = 12 un</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="permite_decimales" id="uniDecimales" value="1"
                                   @checked(old('permite_decimales'))>
                            <label class="form-check-label" for="uniDecimales">Permite cantidades con decimales</label>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="activa" id="uniActiva" value="1"
                                   @checked($errors->any() ? old('activa') : true)>
                            <label class="form-check-label" for="uniActiva">Activa</label>
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
    const rutaAltaUnidad = @json($rutaAlta);
    let unidadEditandoId = null;

    function filtrarBases() {
        const tipo = document.getElementById('uniTipo').value;
        const select = document.getElementById('uniBase');

        for (const opcion of select.options) {
            if (!opcion.value) continue;
            const oculta = opcion.dataset.tipo !== tipo || String(unidadEditandoId) === opcion.value;
            opcion.hidden = oculta;
            if (oculta && opcion.selected) select.value = '';
        }
        mostrarFactor();
    }

    function mostrarFactor() {
        const select = document.getElementById('uniBase');
        const opcion = select.options[select.selectedIndex];
        const tieneBase = !!select.value;

        document.getElementById('uniFactorGrupo').classList.toggle('d-none', !tieneBase);
        document.getElementById('uniFactor').required = tieneBase;
        document.getElementById('uniBaseTexto').textContent = tieneBase ? opcion.dataset.abrev : '';
        document.getElementById('uniAbrevTexto').textContent = document.getElementById('uniAbrev').value || 'unidad';
    }

    function abrirUnidad(datos = null) {
        const url = datos ? datos.url : rutaAltaUnidad;
        unidadEditandoId = datos?.id ?? null;

        document.getElementById('formUnidad').action = url;
        document.getElementById('uniAccion').value = url;
        document.getElementById('uniMetodo').value = datos ? 'PUT' : 'POST';
        document.getElementById('uniTitulo').textContent = datos ? 'Editar unidad' : 'Nueva unidad';

        document.getElementById('uniNombre').value = datos?.nombre ?? '';
        document.getElementById('uniAbrev').value = datos?.abreviatura ?? '';
        document.getElementById('uniTipo').value = datos?.tipo ?? 'unidad';
        document.getElementById('uniBase').value = datos?.unidad_base_id ?? '';
        document.getElementById('uniFactor').value = datos?.unidad_base_id ? datos.factor : '';
        document.getElementById('uniDecimales').checked = datos ? !!datos.permite_decimales : false;
        document.getElementById('uniActiva').checked = datos ? !!datos.activa : true;

        filtrarBases();
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUnidad')).show();
        setTimeout(() => document.getElementById('uniNombre').focus(), 300);
    }

    document.getElementById('uniAbrev').addEventListener('input', mostrarFactor);
    filtrarBases();

    @if ($errors->any())
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUnidad')).show();
    @endif
</script>
@endpush
