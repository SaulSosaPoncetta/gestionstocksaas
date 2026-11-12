{{-- resources/views/inventario/deposito-form.blade.php --}}
@extends('layouts.app')

@section('titulo', $deposito->exists ? 'Editar depósito' : 'Nuevo depósito')

@section('contenido')
    @php
        $negativoActual = $deposito->permite_stock_negativo === null ? '' : ($deposito->permite_stock_negativo ? '1' : '0');
        $negativo = (string) old('permite_stock_negativo', $negativoActual);
    @endphp

    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('inventario.depositos.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <h1 class="h4 mb-0">{{ $deposito->exists ? 'Editar depósito' : 'Nuevo depósito' }}</h1>
    </div>

    <form method="POST" action="{{ $deposito->exists ? route('inventario.depositos.update', $deposito) : route('inventario.depositos.store') }}">
        @csrf
        @if ($deposito->exists)
            @method('PUT')
        @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold">Datos</div>
                    <div class="card-body row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Código *</label>
                            <input type="text" name="codigo" class="form-control text-uppercase" maxlength="20" value="{{ old('codigo', $deposito->codigo) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nombre *</label>
                            <input type="text" name="nombre" class="form-control" maxlength="100" value="{{ old('nombre', $deposito->nombre) }}" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tipo *</label>
                            <select name="tipo" class="form-select" required>
                                @foreach ($tipos as $valor => $texto)
                                    <option value="{{ $valor }}" @selected(old('tipo', $deposito->tipo) === $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Dirección</label>
                            <input type="text" name="direccion" class="form-control" maxlength="255" value="{{ old('direccion', $deposito->direccion) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Localidad</label>
                            <input type="text" name="localidad" class="form-control" maxlength="100" value="{{ old('localidad', $deposito->localidad) }}">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Provincia</label>
                            <input type="text" name="provincia" class="form-control" maxlength="100" value="{{ old('provincia', $deposito->provincia) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Responsable</label>
                            <select name="responsable_id" class="form-select">
                                <option value="">Sin responsable</option>
                                @foreach ($usuarios as $u)
                                    <option value="{{ $u->id }}" @selected((string) old('responsable_id', $deposito->responsable_id) === (string) $u->id)>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control" maxlength="50" value="{{ old('telefono', $deposito->telefono) }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Observaciones</label>
                            <textarea name="observaciones" class="form-control" rows="2" maxlength="2000">{{ old('observaciones', $deposito->observaciones) }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header bg-white fw-semibold">Funcionamiento</div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="activo" id="activo" value="1"
                                   @checked($errors->any() ? old('activo') : $deposito->activo) @disabled($deposito->es_principal)>
                            <label class="form-check-label" for="activo">Activo</label>
                            @if ($deposito->es_principal)
                                <input type="hidden" name="activo" value="1">
                            @endif
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" role="switch" name="es_principal" id="principal" value="1"
                                   @checked($errors->any() ? old('es_principal') : $deposito->es_principal) @disabled($deposito->es_principal)>
                            <label class="form-check-label" for="principal">Depósito principal</label>
                            <div class="form-text">Es el que se usa por defecto en movimientos e integraciones.</div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" name="usa_ubicaciones" id="ubicaciones" value="1"
                                   @checked($errors->any() ? old('usa_ubicaciones') : $deposito->usa_ubicaciones)>
                            <label class="form-check-label" for="ubicaciones">Usa ubicaciones internas</label>
                            <div class="form-text">Zonas, estantes y posiciones para saber dónde está cada producto.</div>
                        </div>

                        <label class="form-label">Stock negativo</label>
                        <select name="permite_stock_negativo" class="form-select">
                            <option value="" @selected($negativo === '')>
                                Igual que la empresa ({{ $empresa->permite_stock_negativo ? 'permitido' : 'no permitido' }})
                            </option>
                            <option value="1" @selected($negativo === '1')>Permitido en este depósito</option>
                            <option value="0" @selected($negativo === '0')>No permitido en este depósito</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Guardar</button>
            <a href="{{ route('inventario.depositos.index') }}" class="btn btn-light">Cancelar</a>
        </div>
    </form>
@endsection
