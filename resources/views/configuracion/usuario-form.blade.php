{{-- resources/views/configuracion/usuario-form.blade.php --}}
@extends('layouts.app')

@section('titulo', $usuario->exists ? 'Editar usuario' : 'Nuevo usuario')

@section('contenido')
    @php($esYo = $usuario->exists && $usuario->is(auth()->user()))

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('configuracion.usuarios.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <h1 class="h4 mb-0">{{ $usuario->exists ? 'Editar usuario' : 'Nuevo usuario' }}</h1>
    </div>

    <form method="POST" action="{{ $usuario->exists ? route('configuracion.usuarios.update', $usuario) : route('configuracion.usuarios.store') }}">
        @csrf
        @if ($usuario->exists)
            @method('PUT')
        @endif

        <div class="card" style="max-width: 720px;">
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nombre *</label>
                    <input type="text" name="name" class="form-control" value="{{ old('name', $usuario->name) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email', $usuario->email) }}" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Rol *</label>
                    <select name="rol" class="form-select" required @disabled($esYo)>
                        @foreach ($roles as $valor => $texto)
                            <option value="{{ $valor }}" @selected(old('rol', $usuario->rol) === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                    @if ($esYo)
                        <input type="hidden" name="rol" value="{{ $usuario->rol }}">
                        <div class="form-text">No podés cambiar tu propio rol.</div>
                    @endif
                </div>

                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input type="hidden" name="activo" value="{{ $esYo ? 1 : 0 }}">
                        <input class="form-check-input" type="checkbox" role="switch" name="activo" id="activo" value="1"
                               @checked(old('activo', $usuario->activo)) @disabled($esYo)>
                        <label class="form-check-label" for="activo">Usuario activo</label>
                    </div>
                </div>

                <div class="col-12"><hr class="my-1"></div>

                <div class="col-md-6">
                    <label class="form-label">Contraseña {{ $usuario->exists ? '' : '*' }}</label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password" @required(! $usuario->exists)>
                    @if ($usuario->exists)
                        <div class="form-text">Dejala vacía para no cambiarla.</div>
                    @else
                        <div class="form-text">Mínimo 8 caracteres.</div>
                    @endif
                </div>
                <div class="col-md-6">
                    <label class="form-label">Repetir contraseña</label>
                    <input type="password" name="password_confirmation" class="form-control" autocomplete="new-password">
                </div>

                <div class="col-12"><hr class="my-1"></div>

                {{-- Depósitos que puede operar --}}
                @php
                    $todos = $errors->any() ? (bool) old('todos_los_depositos') : $usuario->todos_los_depositos;
                    $seleccionados = array_map('intval', old('depositos', $usuario->depositos->pluck('id')->all()));
                @endphp
                <div class="col-12" id="bloqueDepositos">
                    <label class="form-label">Depósitos que puede operar</label>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="todos_los_depositos" id="todosDepositos" value="1"
                               @checked($todos) onchange="mostrarDepositos()">
                        <label class="form-check-label" for="todosDepositos">Todos los depósitos</label>
                    </div>
                    <div id="listaDepositos" class="border rounded p-2" style="max-height: 220px; overflow:auto;">
                        @forelse ($depositos as $d)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="depositos[]" value="{{ $d->id }}" id="dep{{ $d->id }}"
                                       @checked(in_array($d->id, $seleccionados, true))>
                                <label class="form-check-label" for="dep{{ $d->id }}">
                                    {{ $d->etiqueta }}
                                    @if ($d->es_principal)<span class="badge text-bg-light border">Principal</span>@endif
                                </label>
                            </div>
                        @empty
                            <span class="text-muted small">No hay depósitos activos.</span>
                        @endforelse
                    </div>
                    <div class="form-text" id="avisoAdmin">Los administradores operan siempre todos los depósitos.</div>
                </div>

                <div class="col-12">
                    <div class="alert alert-light border small mb-0">
                        <strong>Roles:</strong>
                        <b>Administrador</b> configura todo ·
                        <b>Encargado</b> gestiona depósitos, ajustes e inventarios ·
                        <b>Operador</b> carga movimientos ·
                        <b>Solo consulta</b> ve stock y reportes.
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white">
                <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Guardar</button>
                <a href="{{ route('configuracion.usuarios.index') }}" class="btn btn-light">Cancelar</a>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
<script>
    function mostrarDepositos() {
        const rol = document.querySelector('[name="rol"]:not([type="hidden"])')?.value
            ?? document.querySelector('input[type="hidden"][name="rol"]')?.value;
        const esAdmin = rol === 'admin';
        const todos = document.getElementById('todosDepositos');

        if (esAdmin) {
            todos.checked = true;
        }
        todos.disabled = esAdmin;
        document.getElementById('listaDepositos').classList.toggle('d-none', todos.checked);
        document.getElementById('avisoAdmin').classList.toggle('d-none', !esAdmin);
    }

    document.querySelector('select[name="rol"]')?.addEventListener('change', mostrarDepositos);
    mostrarDepositos();
</script>
@endpush
