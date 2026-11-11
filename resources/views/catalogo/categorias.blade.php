{{-- resources/views/catalogo/categorias.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Categorías')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Categorías</h1>
        <button type="button" class="btn btn-stk" onclick="abrirCategoria()">
            <i class="bi bi-plus-lg"></i> Nueva categoría
        </button>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Código</th>
                    <th class="text-center">Subcategorías</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($categorias as $c)
                    @php($cantidadHijas = $categorias->where('padre_id', $c->id)->count())
                    <tr class="{{ $c->activa ? '' : 'text-muted' }}">
                        <td style="padding-left: {{ 0.75 + $c->nivel * 1.6 }}rem;">
                            @if ($c->nivel > 0)
                                <i class="bi bi-arrow-return-right text-muted me-1"></i>
                            @endif
                            <span class="{{ $c->nivel === 0 ? 'fw-semibold' : '' }}">{{ $c->nombre }}</span>
                            @if ($c->descripcion)
                                <div class="small text-muted" style="margin-left: {{ $c->nivel > 0 ? '1.3rem' : '0' }};">{{ $c->descripcion }}</div>
                            @endif
                        </td>
                        <td>{{ $c->codigo }}</td>
                        <td class="text-center">{{ $cantidadHijas ?: '' }}</td>
                        <td>
                            @if ($c->activa)
                                <span class="badge text-bg-success">Activa</span>
                            @else
                                <span class="badge text-bg-secondary">Inactiva</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Agregar subcategoría"
                                    onclick="abrirCategoria(null, {{ $c->id }})">
                                <i class="bi bi-node-plus"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Editar"
                                    data-categoria="{{ json_encode([
                                        'url' => route('catalogo.categorias.update', $c),
                                        'nombre' => $c->nombre,
                                        'codigo' => $c->codigo,
                                        'descripcion' => $c->descripcion,
                                        'padre_id' => $c->padre_id,
                                        'orden' => $c->orden,
                                        'activa' => $c->activa,
                                    ]) }}"
                                    onclick="abrirCategoria(JSON.parse(this.dataset.categoria))">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('catalogo.categorias.destroy', $c) }}" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar la categoría {{ addslashes($c->nombre) }}?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Eliminar" @disabled($cantidadHijas > 0)>
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">Todavía no hay categorías.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal alta / edición --}}
    @php($rutaAlta = route('catalogo.categorias.store'))
    <div class="modal fade" id="modalCategoria" tabindex="-1">
        <div class="modal-dialog">
            <form method="POST" id="formCategoria" action="{{ old('_accion', $rutaAlta) }}" class="modal-content">
                @csrf
                <input type="hidden" name="_method" id="catMetodo" value="{{ old('_method', 'POST') }}">
                <input type="hidden" name="_accion" id="catAccion" value="{{ old('_accion', $rutaAlta) }}">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="catTitulo">{{ old('_method') === 'PUT' ? 'Editar categoría' : 'Nueva categoría' }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Nombre *</label>
                        <input type="text" name="nombre" id="catNombre" class="form-control" maxlength="100" value="{{ old('nombre') }}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Dentro de</label>
                        <select name="padre_id" id="catPadre" class="form-select">
                            <option value="">— Categoría principal —</option>
                            @foreach ($categorias as $op)
                                <option value="{{ $op->id }}" @selected((string) old('padre_id') === (string) $op->id)>
                                    {{ str_repeat('— ', $op->nivel) }}{{ $op->nombre }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Código</label>
                        <input type="text" name="codigo" id="catCodigo" class="form-control" maxlength="30" value="{{ old('codigo') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Orden</label>
                        <input type="number" name="orden" id="catOrden" class="form-control" min="0" max="9999" value="{{ old('orden', 0) }}">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Descripción</label>
                        <textarea name="descripcion" id="catDescripcion" class="form-control" rows="2" maxlength="500">{{ old('descripcion') }}</textarea>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="activa" id="catActiva" value="1"
                                   @checked($errors->any() ? old('activa') : true)>
                            <label class="form-check-label" for="catActiva">Activa</label>
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
    const rutaAltaCategoria = @json($rutaAlta);

    function abrirCategoria(datos = null, padreId = null) {
        const form = document.getElementById('formCategoria');
        const url = datos ? datos.url : rutaAltaCategoria;

        form.action = url;
        document.getElementById('catAccion').value = url;
        document.getElementById('catMetodo').value = datos ? 'PUT' : 'POST';
        document.getElementById('catTitulo').textContent = datos ? 'Editar categoría' : 'Nueva categoría';

        document.getElementById('catNombre').value = datos?.nombre ?? '';
        document.getElementById('catCodigo').value = datos?.codigo ?? '';
        document.getElementById('catDescripcion').value = datos?.descripcion ?? '';
        document.getElementById('catOrden').value = datos?.orden ?? 0;
        document.getElementById('catPadre').value = datos?.padre_id ?? padreId ?? '';
        document.getElementById('catActiva').checked = datos ? !!datos.activa : true;

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCategoria')).show();
        setTimeout(() => document.getElementById('catNombre').focus(), 300);
    }

    @if ($errors->any())
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCategoria')).show();
    @endif
</script>
@endpush
