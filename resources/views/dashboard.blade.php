{{-- resources/views/dashboard.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Inicio')

@section('contenido')
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <h1 class="h4 mb-0">Hola, {{ auth()->user()->name }}</h1>
            <span class="text-muted small">{{ $empresa->razon_social }} · CUIT {{ $empresa->cuit }}</span>
        </div>
        @if ($empresa->vence_el)
            <span class="badge text-bg-light border">Suscripción hasta {{ $empresa->vence_el->format('d/m/Y') }}</span>
        @endif
    </div>

    @if ($vencimientos['vencidos'] || $vencimientos['por_vencer'])
        <div class="alert {{ $vencimientos['vencidos'] ? 'alert-danger' : 'alert-warning' }} d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span>
                <i class="bi bi-calendar-x"></i>
                @if ($vencimientos['vencidos'])
                    <strong>{{ $vencimientos['vencidos'] }}</strong> {{ $vencimientos['vencidos'] === 1 ? 'lote vencido' : 'lotes vencidos' }} con stock.
                @endif
                @if ($vencimientos['por_vencer'])
                    <strong>{{ $vencimientos['por_vencer'] }}</strong> {{ $vencimientos['por_vencer'] === 1 ? 'lote vence' : 'lotes vencen' }} en los próximos {{ $empresa->dias_aviso_vencimiento }} días.
                @endif
            </span>
            <a href="{{ route('inventario.vencimientos.index') }}" class="btn btn-sm {{ $vencimientos['vencidos'] ? 'btn-danger' : 'btn-warning' }}">Ver vencimientos</a>
        </div>
    @endif

    @if ($transferenciasPendientes->isNotEmpty())
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning-subtle fw-semibold"><i class="bi bi-truck"></i> Transferencias en camino a tus depósitos</div>
            <ul class="list-group list-group-flush">
                @foreach ($transferenciasPendientes as $t)
                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span>
                            N° {{ $t->numero_texto }} · {{ $t->origen?->nombre }} <i class="bi bi-arrow-right"></i> <strong>{{ $t->destino?->nombre }}</strong>
                            <span class="small text-muted">· enviada {{ $t->enviada_at->diffForHumans() }}</span>
                        </span>
                        <a href="{{ route('inventario.transferencias.recepcion', $t) }}" class="btn btn-sm btn-success"><i class="bi bi-box-arrow-in-down"></i> Recibir</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-3 mb-4">
        @foreach ($indicadores as $ind)
            <div class="col-6 col-xl-3">
                <div class="card h-100 {{ $ind['url'] ? 'position-relative' : '' }}">
                    <div class="card-body d-flex align-items-center gap-3">
                        <div class="rounded-3 p-3 bg-{{ $ind['color'] }}-subtle text-{{ $ind['color'] }}">
                            <i class="bi {{ $ind['icono'] }} fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">{{ $ind['titulo'] }}</div>
                            <div class="fs-4 fw-bold">{{ number_format($ind['valor'], 0, ',', '.') }}</div>
                        </div>
                        @if ($ind['url'])
                            <a href="{{ $ind['url'] }}" class="stretched-link" aria-label="{{ $ind['titulo'] }}"></a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders"></i> Configuración de stock</div>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Método de costeo</span>
                        <strong>{{ $empresa->metodo_costeo === 'peps' ? 'PEPS' : 'Promedio ponderado' }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Stock negativo</span>
                        <strong>{{ $empresa->permite_stock_negativo ? 'Permitido' : 'No permitido' }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Lotes y vencimientos</span>
                        <strong>{{ $empresa->usa_lotes ? 'Sí' : 'No' }}</strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span>Números de serie</span>
                        <strong>{{ $empresa->usa_series ? 'Sí' : 'No' }}</strong>
                    </li>
                </ul>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-plug"></i> Sistemas conectados</div>
                <div class="card-body">
                    @forelse ($integraciones as $integracion)
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <span>{{ $integracion->nombre_sistema }}</span>
                            @if ($integracion->ultimo_error)
                                <span class="badge text-bg-danger">Con errores</span>
                            @else
                                <span class="badge text-bg-success">Activa</span>
                            @endif
                        </div>
                    @empty
                        <p class="text-muted mb-2">GestiónStock está funcionando de forma independiente.</p>
                        @if (auth()->user()->tieneRol('admin'))
                            <a href="{{ route('configuracion.integraciones.index') }}" class="btn btn-sm btn-outline-secondary">Ver integraciones disponibles</a>
                        @endif
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
