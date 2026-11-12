{{-- resources/views/inventario/ubicaciones.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Ubicaciones · ' . $deposito->nombre)

@section('contenido')
    @php
        $iconos = ['zona' => 'bi-bounding-box', 'pasillo' => 'bi-signpost-split', 'estante' => 'bi-bookshelf', 'posicion' => 'bi-geo'];
        $rutaAlta = route('inventario.ubicaciones.store', $deposito);
        $formulario = old('_formulario');
    @endphp

    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
        <a href="{{ route('inventario.depositos.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">Ubicaciones</h1>
            <span class="text-muted small"><code>{{ $deposito->codigo }}</code> · {{ $deposito->nombre }}</span>
        </div>
        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalGenerar">
            <i class="bi bi-magic"></i> Generar en lote
        </button>
        <button type="button" class="btn btn-stk" onclick="abrirUbicacion()">
            <i class="bi bi-plus-lg"></i> Nueva ubicación
        </button>
    </div>
    <p class="text-muted small mb-4">Organizá el depósito en zonas, estantes y posiciones. Después, cada movimiento de stock puede indicar en qué ubicación queda la mercadería.</p>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Código</th>
                    <th>Nombre</th>
                    <th>Tipo</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($ubicaciones as $u)
                    @php($tieneHijas = $ubicaciones->contains('padre_id', $u->id))
                    <tr class="{{ $u->activa ? '' : 'text-muted' }}">
                        <td style="padding-left: {{ 0.5 + $u->nivel * 1.5 }}rem;">
                            <i class="bi {{ $iconos[$u->tipo] ?? 'bi-geo' }} text-muted me-1"></i>
                            <code class="{{ $u->nivel === 0 ? 'fw-bold' : '' }}">{{ $u->codigo }}</code>
                        </td>
                        <td>{{ $u->nombre }}</td>
                        <td class="small">{{ $u->tipo_nombre }}</td>
                        <td>
                            @unless ($u->activa)
                                <span class="badge text-bg-secondary">Inactiva</span>
                            @endunless
                        </td>
                        <td class="text-end text-nowrap">
                            @if ($u->tipo !== 'posicion')
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Agregar dentro"
                                        data-padre="{{ json_encode(['id' => $u->id, 'codigo' => $u->codigo, 'tipo' => $u->tipo]) }}"
                                        onclick="abrirUbicacion(null, JSON.parse(this.dataset.padre))">
                                    <i class="bi bi-node-plus"></i>
                                </button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Editar"
                                    data-ubicacion="{{ json_encode([
                                        'url' => route('inventario.ubicaciones.update', [$deposito, $u]),
                                        'codigo' => $u->codigo,
                                        'nombre' => $u->nombre,
                                        'tipo' => $u->tipo,
                                        'padre_id' => $u->padre_id,
                                        'activa' => $u->activa,
                                    ]) }}"
                                    onclick="abrirUbicacion(JSON.parse(this.dataset.ubicacion))">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('inventario.ubicaciones.destroy', [$deposito, $u]) }}" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar la ubicación {{ $u->codigo }}?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Eliminar" @disabled($tieneHijas)><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-5">
                            <i class="bi bi-grid-3x3 fs-1 d-block mb-2"></i>
                            Este depósito todavía no tiene ubicaciones. Probá "Generar en lote".
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal alta / edición --}}
    <div class="modal fade" id="modalUbicacion" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="formUbicacion" action="{{ $formulario === 'ubicacion' ? old('_accion', $rutaAlta) : $rutaAlta }}" class="modal-content">
                @csrf
                <input type="hidden" name="_formulario" value="ubicacion">
                <input type="hidden" name="_method" id="ubiMetodo" value="{{ $formulario === 'ubicacion' ? old('_method', 'POST') : 'POST' }}">
                <input type="hidden" name="_accion" id="ubiAccion" value="{{ $formulario === 'ubicacion' ? old('_accion', $rutaAlta) : $rutaAlta }}">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="ubiTitulo">Nueva ubicación</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Dentro de</label>
                        <select name="padre_id" id="ubiPadre" class="form-select" onchange="sugerirCodigo()">
                            <option value="" data-codigo="">— Nivel principal del depósito —</option>
                            @foreach ($ubicaciones->where('tipo', '!=', 'posicion') as $op)
                                <option value="{{ $op->id }}" data-codigo="{{ $op->codigo }}" data-tipo="{{ $op->tipo }}"
                                        @selected($formulario === 'ubicacion' && (string) old('padre_id') === (string) $op->id)>
                                    {{ str_repeat('— ', $op->nivel) }}{{ $op->codigo }}{{ $op->nombre ? ' · ' . $op->nombre : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tipo *</label>
                        <select name="tipo" id="ubiTipo" class="form-select" required>
                            @foreach ($tipos as $valor => $texto)
                                <option value="{{ $valor }}" @selected($formulario === 'ubicacion' && old('tipo') === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Código *</label>
                        <input type="text" name="codigo" id="ubiCodigo" class="form-control font-monospace text-uppercase" maxlength="40"
                               value="{{ $formulario === 'ubicacion' ? old('codigo') : '' }}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Nombre o descripción</label>
                        <input type="text" name="nombre" id="ubiNombre" class="form-control" maxlength="100"
                               value="{{ $formulario === 'ubicacion' ? old('nombre') : '' }}" placeholder="Opcional (ej. Cámara de frío)">
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="activa" id="ubiActiva" value="1"
                                   @checked($formulario === 'ubicacion' ? old('activa') : true)>
                            <label class="form-check-label" for="ubiActiva">Activa</label>
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

    {{-- Modal generar en lote --}}
    <div class="modal fade" id="modalGenerar" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" action="{{ route('inventario.ubicaciones.generar', $deposito) }}" class="modal-content">
                @csrf
                <input type="hidden" name="_formulario" value="generar">
                <div class="modal-header">
                    <h2 class="modal-title h5">Generar ubicaciones en lote</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-4">
                        <label class="form-label">Zona *</label>
                        <input type="text" name="zona_codigo" id="genZona" class="form-control text-uppercase" maxlength="10"
                               value="{{ $formulario === 'generar' ? old('zona_codigo') : 'A' }}" required>
                    </div>
                    <div class="col-8">
                        <label class="form-label">Nombre de la zona</label>
                        <input type="text" name="zona_nombre" class="form-control" maxlength="100"
                               value="{{ $formulario === 'generar' ? old('zona_nombre') : '' }}" placeholder="Opcional">
                    </div>
                    <div class="col-4">
                        <label class="form-label">Estantes desde *</label>
                        <input type="number" name="estante_desde" id="genDesde" class="form-control" min="1" max="999"
                               value="{{ $formulario === 'generar' ? old('estante_desde') : 1 }}" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">hasta *</label>
                        <input type="number" name="estante_hasta" id="genHasta" class="form-control" min="1" max="999"
                               value="{{ $formulario === 'generar' ? old('estante_hasta') : 5 }}" required>
                    </div>
                    <div class="col-4">
                        <label class="form-label">Niveles por estante</label>
                        <input type="number" name="niveles" id="genNiveles" class="form-control" min="0" max="50"
                               value="{{ $formulario === 'generar' ? old('niveles') : 4 }}" required>
                    </div>
                    <div class="col-12">
                        <div class="alert alert-light border small mb-0" id="genVista"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                    <button class="btn btn-stk"><i class="bi bi-magic"></i> Generar</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    const rutaAltaUbicacion = @json($rutaAlta);
    const tipoHijo = { zona: 'estante', pasillo: 'estante', estante: 'posicion' };
    let editandoUbicacion = false;

    function sugerirCodigo() {
        if (editandoUbicacion) return;
        const opcion = document.getElementById('ubiPadre').selectedOptions[0];
        const codigoPadre = opcion?.dataset.codigo ?? '';
        document.getElementById('ubiCodigo').value = codigoPadre ? codigoPadre + '-' : '';
        document.getElementById('ubiTipo').value = tipoHijo[opcion?.dataset.tipo] ?? 'zona';
    }

    function abrirUbicacion(datos = null, padre = null) {
        const url = datos ? datos.url : rutaAltaUbicacion;
        editandoUbicacion = !!datos;

        document.getElementById('formUbicacion').action = url;
        document.getElementById('ubiAccion').value = url;
        document.getElementById('ubiMetodo').value = datos ? 'PUT' : 'POST';
        document.getElementById('ubiTitulo').textContent = datos ? 'Editar ubicación' : 'Nueva ubicación';

        document.getElementById('ubiPadre').value = datos?.padre_id ?? padre?.id ?? '';
        document.getElementById('ubiNombre').value = datos?.nombre ?? '';
        document.getElementById('ubiActiva').checked = datos ? !!datos.activa : true;

        if (datos) {
            document.getElementById('ubiCodigo').value = datos.codigo;
            document.getElementById('ubiTipo').value = datos.tipo;
        } else {
            sugerirCodigo();
        }

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUbicacion')).show();
        setTimeout(() => {
            const campo = document.getElementById('ubiCodigo');
            campo.focus();
            campo.setSelectionRange(campo.value.length, campo.value.length);
        }, 300);
    }

    function vistaPreviaGenerar() {
        const zona = (document.getElementById('genZona').value || 'A').toUpperCase();
        const desde = parseInt(document.getElementById('genDesde').value) || 1;
        const hasta = parseInt(document.getElementById('genHasta').value) || desde;
        const niveles = parseInt(document.getElementById('genNiveles').value) || 0;
        const digitos = Math.max(2, String(hasta).length);
        const pad = (n) => String(n).padStart(digitos, '0');
        const estantes = Math.max(0, hasta - desde + 1);
        const total = 1 + estantes + estantes * niveles;

        let ejemplo = `${zona} → ${zona}-${pad(desde)}` + (niveles ? ` → ${zona}-${pad(desde)}-1 … ${zona}-${pad(hasta)}-${niveles}` : ` … ${zona}-${pad(hasta)}`);
        document.getElementById('genVista').innerHTML = `<strong>${total}</strong> ubicaciones: ${ejemplo}<br><span class="text-muted">Las que ya existen se saltean.</span>`;
    }

    ['genZona', 'genDesde', 'genHasta', 'genNiveles'].forEach(id => document.getElementById(id).addEventListener('input', vistaPreviaGenerar));
    vistaPreviaGenerar();

    @if ($errors->any())
        bootstrap.Modal.getOrCreateInstance(document.getElementById(@json($formulario === 'generar' ? 'modalGenerar' : 'modalUbicacion'))).show();
    @endif
</script>
@endpush
