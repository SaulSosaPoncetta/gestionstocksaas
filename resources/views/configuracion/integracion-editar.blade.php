{{-- resources/views/configuracion/integracion-editar.blade.php --}}
@extends('layouts.app')

@section('titulo', $config['nombre'])

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('configuracion.integraciones.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0">{{ $config['nombre'] }}</h1>
            <span class="text-muted small">{{ $config['descripcion'] }}</span>
        </div>
    </div>

    {{-- Credenciales recién generadas: se muestran una sola vez --}}
    @foreach (['token_generado' => 'Token para que ' . $config['nombre'] . ' llame a GestiónStock', 'secreto_generado' => 'Clave de firma de webhooks (HMAC)'] as $clave => $titulo)
        @if (session($clave))
            <div class="alert alert-info">
                <div class="fw-semibold mb-1"><i class="bi bi-key"></i> {{ $titulo }}</div>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" value="{{ session($clave) }}" id="cred-{{ $clave }}" readonly>
                    <button class="btn btn-outline-primary" type="button" onclick="copiar('cred-{{ $clave }}', this)"><i class="bi bi-clipboard"></i> Copiar</button>
                </div>
                <div class="small mt-1">Copialo ahora y cargalo en {{ $config['nombre'] }}. Por seguridad no se vuelve a mostrar.</div>
            </div>
        @endif
    @endforeach

    @if ($integracion->ultimo_error)
        <div class="alert alert-danger small">
            <strong>Último error de conexión:</strong> {{ $integracion->ultimo_error }}
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('configuracion.integraciones.update', $sistema) }}">
                @csrf
                @method('PUT')

                <div class="card mb-3">
                    <div class="card-header bg-white fw-semibold">Conexión</div>
                    <div class="card-body row g-3">
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="activa" id="activa" value="1" @checked(old('activa', $integracion->activa))>
                                <label class="form-check-label fw-semibold" for="activa">Integración activa</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">URL de {{ $config['nombre'] }}</label>
                            <input type="url" name="url_base" class="form-control" placeholder="{{ $config['url'] }}" value="{{ old('url_base', $integracion->url_base) }}">
                            <div class="form-text">Dejala vacía para usar la URL estándar.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ID de tu empresa en {{ $config['nombre'] }}</label>
                            <input type="text" name="id_empresa_remota" class="form-control" value="{{ old('id_empresa_remota', $integracion->id_empresa_remota) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Token de {{ $config['nombre'] }}</label>
                            <input type="password" name="token_saliente" class="form-control" autocomplete="off"
                                   placeholder="{{ $integracion->token_saliente ? '•••••••• (guardado)' : 'Sin cargar' }}">
                            <div class="form-text">El que te da {{ $config['nombre'] }}. Vacío = no cambia.</div>
                        </div>
                    </div>
                </div>

                @if (count($campos))
                    <div class="card mb-3">
                        <div class="card-header bg-white fw-semibold">Comportamiento</div>
                        <div class="card-body row g-3">
                            @foreach ($campos as $clave => $campo)
                                @if ($campo['tipo'] === 'deposito')
                                    <div class="col-12">
                                        <label class="form-label">{{ $campo['label'] }}</label>
                                        <select name="opciones[{{ $clave }}]" class="form-select" style="max-width: 420px;">
                                            <option value="">Depósito principal</option>
                                            @foreach ($depositos as $d)
                                                <option value="{{ $d->id }}" @selected((string) old("opciones.$clave", $integracion->opcion($clave)) === (string) $d->id)>{{ $d->etiqueta }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @elseif ($campo['tipo'] === 'bool')
                                    <div class="col-12">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" role="switch" name="opciones[{{ $clave }}]" id="op-{{ $clave }}" value="1"
                                                   @checked(old("opciones.$clave", $integracion->opcion($clave)))>
                                            <label class="form-check-label" for="op-{{ $clave }}">{{ $campo['label'] }}</label>
                                        </div>
                                    </div>
                                @else
                                    <div class="col-md-6">
                                        <label class="form-label">{{ $campo['label'] }}</label>
                                        <select name="opciones[{{ $clave }}]" class="form-select">
                                            @foreach ($campo['opciones'] as $valor => $texto)
                                                <option value="{{ $valor }}" @selected(old("opciones.$clave", $integracion->opcion($clave)) === $valor)>{{ $texto }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endif

                <button class="btn btn-stk"><i class="bi bi-check-lg"></i> Guardar</button>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card">
                <div class="card-header bg-white fw-semibold">Datos para cargar en {{ $config['nombre'] }}</div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item">
                        <div class="text-muted">URL de la API de GestiónStock</div>
                        <code>{{ url('/api/v1') }}</code>
                    </li>
                    <li class="list-group-item">
                        <div class="text-muted">Identificador de la empresa</div>
                        <code class="text-break">{{ $empresa->uuid }}</code>
                    </li>
                    <li class="list-group-item">
                        <div class="text-muted">CUIT</div>
                        <code>{{ $empresa->cuit }}</code>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="text-muted">Token de acceso</div>
                            {{ $integracion->token_entrante_hash ? 'Generado' : 'No generado' }}
                        </div>
                        <form method="POST" action="{{ route('configuracion.integraciones.token', $sistema) }}"
                              onsubmit="return {{ $integracion->token_entrante_hash ? "confirm('El token actual va a dejar de funcionar. ¿Continuar?')" : 'true' }};">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">{{ $integracion->token_entrante_hash ? 'Regenerar' : 'Generar' }}</button>
                        </form>
                    </li>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div class="text-muted">Clave de firma de webhooks</div>
                            {{ $integracion->webhook_secreto ? 'Generada' : 'No generada' }}
                        </div>
                        <form method="POST" action="{{ route('configuracion.integraciones.secreto', $sistema) }}"
                              onsubmit="return {{ $integracion->webhook_secreto ? "confirm('La clave actual va a dejar de funcionar. ¿Continuar?')" : 'true' }};">
                            @csrf
                            <button class="btn btn-sm btn-outline-secondary">{{ $integracion->webhook_secreto ? 'Regenerar' : 'Generar' }}</button>
                        </form>
                    </li>
                </ul>
                <div class="card-footer bg-white small text-muted">
                    La API queda disponible en el paso de integraciones. Ya podés dejar las credenciales generadas.
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
    function copiar(id, boton) {
        const campo = document.getElementById(id);
        navigator.clipboard.writeText(campo.value).then(() => {
            boton.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
        });
    }
</script>
@endpush
