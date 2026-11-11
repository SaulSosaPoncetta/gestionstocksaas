{{-- resources/views/catalogo/marcas.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Marcas')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h1 class="h4 mb-0">Marcas</h1>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex">
                <input type="search" name="q" class="form-control" placeholder="Buscar marca…" value="{{ $buscar }}">
            </form>
            <button type="button" class="btn btn-stk text-nowrap" onclick="abrirMarca()">
                <i class="bi bi-plus-lg"></i> Nueva marca
            </button>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Estado</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($marcas as $m)
                    <tr class="{{ $m->activa ? '' : 'text-muted' }}">
                        <td class="fw-semibold">{{ $m->nombre }}</td>
                        <td>
                            @if ($m->activa)
                                <span class="badge text-bg-success">Activa</span>
                            @else
                                <span class="badge text-bg-secondary">Inactiva</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Editar"
                                    data-marca="{{ json_encode([
                                        'url' => route('catalogo.marcas.update', $m),
                                        'nombre' => $m->nombre,
                                        'activa' => $m->activa,
                                    ]) }}"
                                    onclick="abrirMarca(JSON.parse(this.dataset.marca))">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('catalogo.marcas.destroy', $m) }}" class="d-inline"
                                  onsubmit="return confirm('¿Eliminar la marca {{ addslashes($m->nombre) }}?');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="text-center text-muted py-4">
                            {{ $buscar !== '' ? 'No hay marcas que coincidan con la búsqueda.' : 'Todavía no hay marcas.' }}
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($marcas->hasPages())
            <div class="card-footer bg-white">{{ $marcas->links() }}</div>
        @endif
    </div>

    {{-- Modal alta / edición --}}
    @php($rutaAlta = route('catalogo.marcas.store'))
    <div class="modal fade" id="modalMarca" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <form method="POST" id="formMarca" action="{{ old('_accion', $rutaAlta) }}" class="modal-content">
                @csrf
                <input type="hidden" name="_method" id="marMetodo" value="{{ old('_method', 'POST') }}">
                <input type="hidden" name="_accion" id="marAccion" value="{{ old('_accion', $rutaAlta) }}">

                <div class="modal-header">
                    <h2 class="modal-title h5" id="marTitulo">{{ old('_method') === 'PUT' ? 'Editar marca' : 'Nueva marca' }}</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="nombre" id="marNombre" class="form-control mb-3" maxlength="100" value="{{ old('nombre') }}" required>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="activa" id="marActiva" value="1"
                               @checked($errors->any() ? old('activa') : true)>
                        <label class="form-check-label" for="marActiva">Activa</label>
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
    const rutaAltaMarca = @json($rutaAlta);

    function abrirMarca(datos = null) {
        const url = datos ? datos.url : rutaAltaMarca;

        document.getElementById('formMarca').action = url;
        document.getElementById('marAccion').value = url;
        document.getElementById('marMetodo').value = datos ? 'PUT' : 'POST';
        document.getElementById('marTitulo').textContent = datos ? 'Editar marca' : 'Nueva marca';
        document.getElementById('marNombre').value = datos?.nombre ?? '';
        document.getElementById('marActiva').checked = datos ? !!datos.activa : true;

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMarca')).show();
        setTimeout(() => document.getElementById('marNombre').focus(), 300);
    }

    @if ($errors->any())
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMarca')).show();
    @endif
</script>
@endpush
