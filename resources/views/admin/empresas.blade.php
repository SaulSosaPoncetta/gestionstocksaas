{{-- resources/views/admin/empresas.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Empresas')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h4 mb-0">Empresas</h1>
        <button class="btn btn-stk" type="button" data-bs-toggle="collapse" data-bs-target="#nuevaEmpresa">
            <i class="bi bi-plus-lg"></i> Nueva empresa
        </button>
    </div>

    <div class="collapse {{ $errors->any() ? 'show' : '' }} mb-4" id="nuevaEmpresa">
        <div class="card">
            <div class="card-header bg-white fw-semibold">Alta de empresa y su administrador</div>
            <form method="POST" action="{{ route('admin.empresas.store') }}">
                @csrf
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Razón social *</label>
                        <input type="text" name="razon_social" class="form-control" value="{{ old('razon_social') }}" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nombre de fantasía</label>
                        <input type="text" name="nombre_fantasia" class="form-control" value="{{ old('nombre_fantasia') }}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">CUIT *</label>
                        <input type="text" name="cuit" class="form-control" placeholder="30-12345678-9" value="{{ old('cuit') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Condición IVA *</label>
                        <select name="condicion_iva" class="form-select" required>
                            @foreach ($condiciones as $valor => $texto)
                                <option value="{{ $valor }}" @selected(old('condicion_iva', 'responsable_inscripto') === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Suscripción vence el</label>
                        <input type="date" name="vence_el" class="form-control" value="{{ old('vence_el') }}">
                    </div>

                    <div class="col-12"><hr class="my-1"><span class="small text-muted">Usuario administrador</span></div>

                    <div class="col-md-4">
                        <label class="form-label">Nombre *</label>
                        <input type="text" name="admin_nombre" class="form-control" value="{{ old('admin_nombre') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email *</label>
                        <input type="email" name="admin_email" class="form-control" value="{{ old('admin_email') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Contraseña *</label>
                        <input type="password" name="admin_password" class="form-control" autocomplete="new-password" required>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Crear empresa</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Empresa</th>
                    <th>CUIT</th>
                    <th class="text-center">Usuarios</th>
                    <th>Suscripción</th>
                    <th>Módulos</th>
                    <th class="text-end">Acción</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($empresas as $e)
                    <tr>
                        <td>
                            <div class="fw-semibold">{{ $e->nombre }}</div>
                            @if ($e->nombre_fantasia)
                                <div class="small text-muted">{{ $e->razon_social }}</div>
                            @endif
                        </td>
                        <td>{{ $e->cuit }}</td>
                        <td class="text-center">{{ $e->usuarios_count }}</td>
                        <td>
                            @if ($e->suscripcionVigente())
                                <span class="badge text-bg-success">Vigente</span>
                            @else
                                <span class="badge text-bg-danger">Vencida / inactiva</span>
                            @endif
                            @if ($e->vence_el)
                                <div class="small text-muted">hasta {{ $e->vence_el->format('d/m/Y') }}</div>
                            @endif
                        </td>
                        <td>
                            <form method="POST" action="{{ route('admin.empresas.modulos', $e) }}" class="small">
                                @csrf
                                @method('PATCH')
                                @foreach ($modulos as $clave => $descripcion)
                                    <div class="form-check form-switch mb-0" title="{{ $descripcion }}">
                                        <input class="form-check-input" type="checkbox" role="switch" name="modulos[{{ $clave }}]" value="1"
                                               id="mod{{ $e->id }}{{ $clave }}" @checked($e->tieneModulo($clave)) onchange="this.form.submit()">
                                        <label class="form-check-label" for="mod{{ $e->id }}{{ $clave }}">{{ ucfirst($clave) }}</label>
                                    </div>
                                @endforeach
                            </form>
                        </td>
                        <td class="text-end">
                            @if ($actualId === $e->id)
                                <span class="badge text-bg-warning">Operando</span>
                            @else
                                <form method="POST" action="{{ route('admin.empresas.entrar', $e) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">Todavía no hay empresas.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
