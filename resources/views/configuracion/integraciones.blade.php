{{-- resources/views/configuracion/integraciones.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Integraciones')

@section('contenido')
    <h1 class="h4 mb-1">Integraciones</h1>
    <p class="text-muted mb-4">
        GestiónStock funciona solo. Si tu empresa usa otros sistemas de MiGestión, podés conectarlos para que el stock se mueva automáticamente.
    </p>

    @php
        $iconos = ['panel' => 'bi-speedometer', 'comercial' => 'bi-cart3', 'mercado' => 'bi-shop', 'contable' => 'bi-journal-text'];
    @endphp

    <div class="row g-3">
        @foreach ($sistemas as $clave => $sistema)
            @php
                $actual = $actuales->get($clave);
                $soloMiGestion = $clave === 'panel' && ! auth()->user()->esSuperadmin();
            @endphp
            <div class="col-md-6 col-xl-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div class="rounded-3 p-2 bg-body-tertiary"><i class="bi {{ $iconos[$clave] ?? 'bi-plug' }} fs-4"></i></div>
                            @if (! $actual)
                                <span class="badge text-bg-light border">No configurada</span>
                            @elseif (! $actual->activa)
                                <span class="badge text-bg-secondary">Inactiva</span>
                            @elseif ($actual->ultimo_error)
                                <span class="badge text-bg-danger">Con errores</span>
                            @else
                                <span class="badge text-bg-success">Activa</span>
                            @endif
                        </div>
                        <h2 class="h6 fw-bold">{{ $sistema['nombre'] }}</h2>
                        <p class="small text-muted mb-2">{{ $sistema['descripcion'] }}</p>
                        @if ($actual?->ultimo_ok_at)
                            <div class="small text-success">Última conexión: {{ $actual->ultimo_ok_at->format('d/m/Y H:i') }}</div>
                        @endif
                    </div>
                    <div class="card-footer bg-white">
                        @if ($soloMiGestion)
                            <span class="small text-muted"><i class="bi bi-lock"></i> La administra MiGestión</span>
                        @else
                            <a href="{{ route('configuracion.integraciones.edit', $clave) }}" class="btn btn-sm btn-outline-secondary w-100">
                                <i class="bi bi-gear"></i> Configurar
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
