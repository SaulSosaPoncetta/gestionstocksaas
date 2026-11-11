{{-- resources/views/configuracion/empresa.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Empresa')

@section('contenido')
    <h1 class="h4 mb-4">Datos de la empresa</h1>

    <form method="POST" action="{{ route('configuracion.empresa.update') }}" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold">Datos fiscales y de contacto</div>
                    <div class="card-body row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Razón social *</label>
                            <input type="text" name="razon_social" class="form-control" value="{{ old('razon_social', $empresa->razon_social) }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nombre de fantasía</label>
                            <input type="text" name="nombre_fantasia" class="form-control" value="{{ old('nombre_fantasia', $empresa->nombre_fantasia) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">CUIT</label>
                            <input type="text" class="form-control" value="{{ $empresa->cuit }}" disabled>
                            <div class="form-text">Para cambiar el CUIT comunicate con MiGestión.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Condición frente al IVA *</label>
                            <select name="condicion_iva" class="form-select" required>
                                @foreach ($condiciones as $valor => $texto)
                                    <option value="{{ $valor }}" @selected(old('condicion_iva', $empresa->condicion_iva) === $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Domicilio</label>
                            <input type="text" name="domicilio" class="form-control" value="{{ old('domicilio', $empresa->domicilio) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Localidad</label>
                            <input type="text" name="localidad" class="form-control" value="{{ old('localidad', $empresa->localidad) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Provincia</label>
                            <input type="text" name="provincia" class="form-control" value="{{ old('provincia', $empresa->provincia) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control" value="{{ old('telefono', $empresa->telefono) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="{{ old('email', $empresa->email) }}">
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-white fw-semibold">Configuración de stock</div>
                    <div class="card-body row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Método de costeo *</label>
                            <select name="metodo_costeo" class="form-select" required>
                                @foreach ($metodos as $valor => $texto)
                                    <option value="{{ $valor }}" @selected(old('metodo_costeo', $empresa->metodo_costeo) === $valor)>{{ $texto }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Definilo antes de empezar a cargar movimientos.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Decimales en cantidades *</label>
                            <input type="number" name="decimales_cantidad" class="form-control" min="0" max="4" value="{{ old('decimales_cantidad', $empresa->decimales_cantidad) }}" required>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="permite_stock_negativo" id="permite_stock_negativo" value="1" @checked(old('permite_stock_negativo', $empresa->permite_stock_negativo))>
                                <label class="form-check-label" for="permite_stock_negativo">Permitir stock negativo</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="usa_lotes" id="usa_lotes" value="1" @checked(old('usa_lotes', $empresa->usa_lotes))>
                                <label class="form-check-label" for="usa_lotes">Trabajar con lotes y fechas de vencimiento</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="usa_series" id="usa_series" value="1" @checked(old('usa_series', $empresa->usa_series))>
                                <label class="form-check-label" for="usa_series">Trabajar con números de serie</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold">Logo</div>
                    <div class="card-body text-center">
                        @if ($empresa->logo)
                            <img src="{{ asset('storage/' . $empresa->logo) }}" alt="Logo" class="img-fluid mb-3" style="max-height: 120px;">
                        @else
                            <div class="text-muted py-4"><i class="bi bi-image fs-1"></i><br>Sin logo</div>
                        @endif
                        <input type="file" name="logo" class="form-control" accept="image/*">
                        <div class="form-text">PNG o JPG, hasta 2 MB.</div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header bg-white fw-semibold">Ecosistema MiGestión</div>
                    <div class="card-body small">
                        <div class="text-muted">Identificador de la empresa</div>
                        <code class="d-block text-break">{{ $empresa->uuid }}</code>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3">
            <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Guardar cambios</button>
        </div>
    </form>
@endsection
