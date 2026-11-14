{{-- resources/views/inventario/toma-form.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Nueva toma de inventario')

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('inventario.tomas.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-clipboard-check"></i> Nueva toma de inventario</h1>
            <span class="text-muted small">Se arma la lista con lo que el sistema tiene en el alcance que elijas. Después se cuenta y, al cerrar, se ajustan las diferencias.</span>
        </div>
    </div>

    <form method="POST" action="{{ route('inventario.tomas.store') }}">
        @csrf
        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">¿Qué se cuenta?</div>
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Depósito *</label>
                    <select name="deposito_id" id="deposito" class="form-select" required>
                        @foreach ($depositos as $d)
                            <option value="{{ $d->id }}" @selected($depositoInicial === $d->id)>{{ $d->etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4" id="bloqueUbicacion">
                    <label class="form-label">Ubicación</label>
                    <select name="ubicacion_id" id="ubicacion" class="form-select"></select>
                    <div class="form-text">Incluye sus sububicaciones. Ideal para conteos cíclicos por estante.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Categoría</label>
                    <select name="categoria_id" class="form-select">
                        <option value="">Todas</option>
                        @foreach ($categorias as $c)
                            <option value="{{ $c->id }}" @selected((string) old('categoria_id') === (string) $c->id)>{{ str_repeat('— ', $c->nivel) }}{{ $c->nombre }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Incluye las subcategorías.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Descripción</label>
                    <input type="text" name="descripcion" class="form-control" maxlength="255" value="{{ old('descripcion') }}" placeholder="Ej.: Inventario anual 2026 / Conteo cíclico bebidas">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones') }}">
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-white fw-semibold">Opciones</div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" name="congela" id="congela" value="1" @checked(old('congela', true))>
                    <label class="form-check-label" for="congela"><strong>Congelar el depósito mientras se cuenta</strong></label>
                    <div class="form-text">Nadie puede mover stock ahí (ventas, remitos, transferencias, ajustes) hasta cerrar o cancelar la toma. Recomendado para el inventario general. Si no lo congelás, el sistema respeta igual los movimientos que pasen después de contar cada renglón.</div>
                </div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" name="a_ciegas" id="a_ciegas" value="1" @checked(old('a_ciegas'))>
                    <label class="form-check-label" for="a_ciegas"><strong>Conteo a ciegas</strong></label>
                    <div class="form-text">El operador no ve cuánto dice el sistema: cuenta lo que hay. Admin y encargado ven las diferencias.</div>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="incluir_sin_stock" id="incluir_sin_stock" value="1" @checked(old('incluir_sin_stock'))>
                    <label class="form-check-label" for="incluir_sin_stock"><strong>Incluir productos sin stock</strong></label>
                    <div class="form-text">Lista también los productos activos del alcance que el sistema da en cero (para detectar sobrantes).</div>
                </div>
            </div>
        </div>

        <button class="btn btn-stk"><i class="bi bi-play-fill"></i> Iniciar toma</button>
    </form>
@endsection

@push('scripts')
<script>
    (() => {
        const UBICACIONES = @json($ubicaciones);
        const anterior = @json((string) old('ubicacion_id', ''));
        const deposito = document.getElementById('deposito');
        const ubicacion = document.getElementById('ubicacion');
        const bloque = document.getElementById('bloqueUbicacion');

        function cargar() {
            const lista = UBICACIONES[deposito.value] || [];
            bloque.classList.toggle('d-none', !lista.length);
            ubicacion.innerHTML = '<option value="">Todo el depósito</option>'
                + lista.map((u) => `<option value="${u.id}" ${String(u.id) === anterior ? 'selected' : ''}>${u.etiqueta}</option>`).join('');
        }
        deposito.addEventListener('change', cargar);
        cargar();
    })();
</script>
@endpush
