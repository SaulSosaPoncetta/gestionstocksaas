{{-- resources/views/compras/proveedor-form.blade.php --}}
@extends('layouts.app')

@section('titulo', $proveedor->exists ? 'Editar proveedor' : 'Nuevo proveedor')

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ $proveedor->exists ? route('compras.proveedores.show', $proveedor) : route('compras.proveedores.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <h1 class="h4 mb-0">{{ $proveedor->exists ? 'Editar proveedor' : 'Nuevo proveedor' }}</h1>
    </div>

    <form method="POST" action="{{ $proveedor->exists ? route('compras.proveedores.update', $proveedor) : route('compras.proveedores.store') }}">
        @csrf
        @if ($proveedor->exists) @method('PUT') @endif

        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Razón social *</label>
                    <input type="text" name="razon_social" class="form-control" maxlength="255" value="{{ old('razon_social', $proveedor->razon_social) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nombre de fantasía</label>
                    <input type="text" name="nombre_fantasia" class="form-control" maxlength="255" value="{{ old('nombre_fantasia', $proveedor->nombre_fantasia) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">CUIT</label>
                    <input type="text" name="cuit" class="form-control" placeholder="30-12345678-9" value="{{ old('cuit', $proveedor->cuit) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Condición IVA *</label>
                    <select name="condicion_iva" class="form-select" required>
                        @foreach ($condiciones as $valor => $texto)
                            <option value="{{ $valor }}" @selected(old('condicion_iva', $proveedor->condicion_iva) === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Contacto</label>
                    <input type="text" name="contacto" class="form-control" maxlength="150" value="{{ old('contacto', $proveedor->contacto) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email (para enviarle las órdenes)</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email', $proveedor->email) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="telefono" class="form-control" maxlength="50" value="{{ old('telefono', $proveedor->telefono) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Domicilio</label>
                    <input type="text" name="domicilio" class="form-control" value="{{ old('domicilio', $proveedor->domicilio) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Localidad</label>
                    <input type="text" name="localidad" class="form-control" value="{{ old('localidad', $proveedor->localidad) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Provincia</label>
                    <input type="text" name="provincia" class="form-control" value="{{ old('provincia', $proveedor->provincia) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Condiciones de pago</label>
                    <input type="text" name="condiciones_pago" class="form-control" maxlength="150" placeholder="Ej.: 30 días fecha factura" value="{{ old('condiciones_pago', $proveedor->condiciones_pago) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Plazo de entrega</label>
                    <div class="input-group">
                        <input type="number" name="plazo_entrega_dias" class="form-control" min="0" max="365" value="{{ old('plazo_entrega_dias', $proveedor->plazo_entrega_dias) }}">
                        <span class="input-group-text">días</span>
                    </div>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="activo" id="activo" value="1" @checked($errors->any() ? old('activo') : $proveedor->activo)>
                        <label class="form-check-label" for="activo">Activo</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="2" maxlength="2000">{{ old('observaciones', $proveedor->observaciones) }}</textarea>
                </div>
            </div>
        </div>

        <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Guardar</button>
    </form>
@endsection
