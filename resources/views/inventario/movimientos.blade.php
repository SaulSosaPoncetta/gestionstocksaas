{{-- resources/views/inventario/movimientos.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Movimientos')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">Movimientos de stock</h1>
        @if ($tiposManuales)
            <div class="dropdown">
                <button class="btn btn-stk dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    <i class="bi bi-plus-lg"></i> Nuevo movimiento
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @foreach ($tiposManuales as $clave => $config)
                        <li>
                            <a class="dropdown-item" href="{{ route('inventario.movimientos.create', $clave) }}">
                                <i class="bi {{ $config['icono'] }}"></i> {{ $config['titulo'] }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-lg-2">
                <label class="form-label small mb-1">Buscar</label>
                <input type="search" name="q" class="form-control" placeholder="N°, comprobante, referencia…" value="{{ $filtros['q'] }}">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Tipo</label>
                <select name="tipo" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($tipos as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['tipo'] === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Depósito</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Motivo</label>
                <select name="motivo_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($motivos as $tipoMotivo => $lista)
                        <optgroup label="{{ $tipos[$tipoMotivo] ?? $tipoMotivo }}">
                            @foreach ($lista as $mot)
                                <option value="{{ $mot->id }}" @selected($filtros['motivo_id'] === $mot->id)>{{ $mot->nombre }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="col-4 col-lg-1">
                <label class="form-label small mb-1">Estado</label>
                <select name="estado" class="form-select">
                    <option value="">Todos</option>
                    <option value="confirmado" @selected($filtros['estado'] === 'confirmado')>Vigentes</option>
                    <option value="anulado" @selected($filtros['estado'] === 'anulado')>Anulados</option>
                </select>
            </div>
            <div class="col-4 col-lg-1">
                <label class="form-label small mb-1">Desde</label>
                <input type="date" name="desde" class="form-control" value="{{ $filtros['desde'] }}">
            </div>
            <div class="col-4 col-lg-1">
                <label class="form-label small mb-1">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="{{ $filtros['hasta'] }}">
            </div>
            <div class="col-lg-1 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>N°</th>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Depósito</th>
                    <th class="text-center">Renglones</th>
                    <th>Comprobante / observaciones</th>
                    <th>Usuario</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($movimientos as $m)
                    <tr class="{{ $m->estaAnulado() ? 'text-muted' : '' }}">
                        <td class="fw-semibold text-nowrap">
                            <a href="{{ route('inventario.movimientos.show', $m) }}" class="text-decoration-none">{{ $m->numero_texto }}</a>
                        </td>
                        <td class="small text-nowrap">{{ $m->fecha->format('d/m/Y H:i') }}</td>
                        <td>
                            <span class="badge text-bg-{{ $m->color }}">{{ $m->tipo_nombre }}</span>
                            @if ($m->estaAnulado())
                                <span class="badge text-bg-dark">Anulado</span>
                            @endif
                            @if ($m->motivo)
                                <div class="small text-muted">{{ $m->motivo->nombre }}</div>
                            @endif
                        </td>
                        <td class="small">
                            {{ $m->deposito?->nombre }}
                            @if ($m->depositoDestino)
                                <i class="bi bi-arrow-right"></i> {{ $m->depositoDestino->nombre }}
                            @endif
                        </td>
                        <td class="text-center">{{ $m->items_count }}</td>
                        <td class="small">
                            @if ($m->comprobante)
                                <div>{{ $m->comprobante }}</div>
                            @endif
                            <div class="text-muted text-truncate" style="max-width: 280px;">{{ $m->observaciones }}</div>
                        </td>
                        <td class="small">
                            {{ $m->usuario?->name ?? $m->origen_nombre }}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('inventario.movimientos.show', $m) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-arrow-left-right fs-1 d-block mb-2"></i>
                            No hay movimientos para los filtros elegidos.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($movimientos->hasPages())
            <div class="card-footer bg-white">{{ $movimientos->links() }}</div>
        @endif
    </div>
@endsection
