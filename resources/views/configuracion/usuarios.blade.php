{{-- resources/views/configuracion/usuarios.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Usuarios')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Usuarios</h1>
        <a href="{{ route('configuracion.usuarios.create') }}" class="btn btn-stk"><i class="bi bi-plus-lg"></i> Nuevo usuario</a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Nombre</th>
                    <th>Email</th>
                    <th>Rol</th>
                    <th>Depósitos</th>
                    <th>Estado</th>
                    <th>Último acceso</th>
                    <th class="text-end">Acciones</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($usuarios as $u)
                    <tr class="{{ $u->activo ? '' : 'text-muted' }}">
                        <td class="fw-semibold">
                            {{ $u->name }}
                            @if ($u->is(auth()->user()))
                                <span class="badge text-bg-light border">Vos</span>
                            @endif
                        </td>
                        <td>{{ $u->email }}</td>
                        <td><span class="badge text-bg-secondary">{{ $u->rol_nombre }}</span></td>
                        <td class="small">
                            @if ($u->operaTodosLosDepositos())
                                <span class="text-muted">Todos</span>
                            @else
                                {{ $u->depositos->pluck('nombre')->implode(', ') ?: 'Ninguno' }}
                            @endif
                        </td>
                        <td>
                            @if ($u->activo)
                                <span class="badge text-bg-success">Activo</span>
                            @else
                                <span class="badge text-bg-danger">Inactivo</span>
                            @endif
                        </td>
                        <td class="small">{{ $u->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('configuracion.usuarios.edit', $u) }}" class="btn btn-sm btn-outline-secondary" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </a>
                            @unless ($u->is(auth()->user()))
                                <form method="POST" action="{{ route('configuracion.usuarios.toggle', $u) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <button class="btn btn-sm {{ $u->activo ? 'btn-outline-danger' : 'btn-outline-success' }}" title="{{ $u->activo ? 'Desactivar' : 'Activar' }}">
                                        <i class="bi {{ $u->activo ? 'bi-person-x' : 'bi-person-check' }}"></i>
                                    </button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No hay usuarios cargados.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
