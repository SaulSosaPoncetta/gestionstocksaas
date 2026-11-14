{{-- resources/views/salidas/cliente-form.blade.php --}}
@extends('layouts.app')

@section('titulo', $cliente->exists ? 'Editar cliente' : 'Nuevo cliente')

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ $cliente->exists ? route('salidas.clientes.show', $cliente) : route('salidas.clientes.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <h1 class="h4 mb-0">{{ $cliente->exists ? 'Editar cliente' : 'Nuevo cliente' }}</h1>
    </div>

    <form method="POST" action="{{ $cliente->exists ? route('salidas.clientes.update', $cliente) : route('salidas.clientes.store') }}">
        @csrf
        @if ($cliente->exists) @method('PUT') @endif
        <input type="hidden" name="volver" value="{{ old('volver', request('volver')) }}">

        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-6">
                    <label class="form-label">Razón social / nombre *</label>
                    <input type="text" name="razon_social" class="form-control" maxlength="255" value="{{ old('razon_social', $cliente->razon_social) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nombre de fantasía</label>
                    <input type="text" name="nombre_fantasia" class="form-control" maxlength="255" value="{{ old('nombre_fantasia', $cliente->nombre_fantasia) }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Documento</label>
                    <div class="input-group">
                        <select name="documento_tipo" class="form-select" style="max-width: 100px;">
                            @foreach ($documentos as $valor => $texto)
                                <option value="{{ $valor }}" @selected(old('documento_tipo', $cliente->documento_tipo) === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <input type="text" name="documento" class="form-control" maxlength="20" value="{{ old('documento', $cliente->documento) }}">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Condición IVA *</label>
                    <select name="condicion_iva" class="form-select" required>
                        @foreach ($condiciones as $valor => $texto)
                            <option value="{{ $valor }}" @selected(old('condicion_iva', $cliente->condicion_iva) === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Contacto</label>
                    <input type="text" name="contacto" class="form-control" maxlength="150" value="{{ old('contacto', $cliente->contacto) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="{{ old('email', $cliente->email) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Teléfono</label>
                    <input type="text" name="telefono" class="form-control" maxlength="50" value="{{ old('telefono', $cliente->telefono) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Domicilio de entrega</label>
                    <input type="text" name="domicilio" class="form-control" value="{{ old('domicilio', $cliente->domicilio) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Localidad</label>
                    <input type="text" name="localidad" class="form-control" value="{{ old('localidad', $cliente->localidad) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Provincia</label>
                    <input type="text" name="provincia" class="form-control" value="{{ old('provincia', $cliente->provincia) }}">
                </div>
                <div class="col-md-9">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones', $cliente->observaciones) }}">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="activo" id="activo" value="1" @checked($errors->any() ? old('activo') : $cliente->activo)>
                        <label class="form-check-label" for="activo">Activo</label>
                    </div>
                </div>
            </div>
        </div>

        <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Guardar</button>
    </form>
@endsection
