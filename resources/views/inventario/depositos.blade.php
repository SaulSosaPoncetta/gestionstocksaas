{{-- resources/views/inventario/depositos.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Depósitos')

@section('contenido')
    @php($esAdmin = auth()->user()->tieneRol('admin'))

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
        <h1 class="h4 mb-0">Depósitos</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('inventario.depositos.index', $verTodos ? [] : ['estado' => 'todos']) }}" class="btn btn-light">
                {{ $verTodos ? 'Ver solo activos' : 'Ver también inactivos' }}
            </a>
            @if ($esAdmin)
                <a href="{{ route('inventario.depositos.create') }}" class="btn btn-stk"><i class="bi bi-plus-lg"></i> Nuevo depósito</a>
            @endif
        </div>
    </div>

    <div class="row g-3">
        @forelse ($depositos as $d)
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 {{ $d->activo ? '' : 'opacity-75' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="small text-muted"><code>{{ $d->codigo }}</code> · {{ $d->tipo_nombre }}</div>
                                <h2 class="h5 mb-0">{{ $d->nombre }}</h2>
                            </div>
                            <div class="text-end">
                                @if ($d->es_principal)
                                    <span class="badge text-bg-primary">Principal</span>
                                @endif
                                @unless ($d->activo)
                                    <span class="badge text-bg-secondary">Inactivo</span>
                                @endunless
                            </div>
                        </div>

                        <ul class="list-unstyled small text-muted mb-0">
                            @if ($d->direccion || $d->localidad)
                                <li><i class="bi bi-geo-alt"></i> {{ collect([$d->direccion, $d->localidad])->filter()->implode(', ') }}</li>
                            @endif
                            @if ($d->responsable)
                                <li><i class="bi bi-person"></i> {{ $d->responsable->name }}</li>
                            @endif
                            <li>
                                <i class="bi bi-grid-3x3"></i>
                                @if ($d->usa_ubicaciones)
                                    {{ $d->ubicaciones_count }} ubicaciones
                                @else
                                    Sin ubicaciones internas
                                @endif
                            </li>
                            @if ($d->permite_stock_negativo !== null)
                                <li><i class="bi bi-exclamation-diamond"></i> Stock negativo: {{ $d->permite_stock_negativo ? 'permitido' : 'no permitido' }}</li>
                            @endif
                        </ul>
                    </div>
                    <div class="card-footer bg-white d-flex flex-wrap gap-1">
                        <a href="{{ route('inventario.ubicaciones.index', $d) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-grid-3x3"></i> Ubicaciones
                        </a>
                        @if ($esAdmin)
                            <a href="{{ route('inventario.depositos.edit', $d) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Editar</a>
                            @if (! $d->es_principal && $d->activo)
                                <form method="POST" action="{{ route('inventario.depositos.principal', $d) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-primary"><i class="bi bi-star"></i> Hacer principal</button>
                                </form>
                            @endif
                            @unless ($d->es_principal)
                                <form method="POST" action="{{ route('inventario.depositos.destroy', $d) }}" class="ms-auto"
                                      onsubmit="return confirm('¿Eliminar el depósito {{ addslashes($d->nombre) }}?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                                </form>
                            @endunless
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card card-body text-center text-muted py-5">No hay depósitos.</div>
            </div>
        @endforelse
    </div>
@endsection
