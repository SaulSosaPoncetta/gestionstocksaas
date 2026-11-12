{{-- resources/views/inventario/kardex.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Kardex · ' . $producto->nombre)

@section('contenido')
    @php($unidad = $producto->unidad?->abreviatura)

    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('inventario.stock.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">Kardex</h1>
            <span class="text-muted small">
                {{ $producto->nombre }} · <code>{{ $producto->sku }}</code>
                @if ($producto->padre)
                    · variante de {{ $producto->padre->nombre }}
                @endif
            </span>
        </div>
        @if (auth()->user()->tieneRol('admin', 'encargado'))
            <a href="{{ route('catalogo.productos.edit', $producto) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i> Ficha del producto</a>
        @endif
    </div>

    {{-- Stock actual --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="text-muted small">Stock total</div>
                    <div class="fs-3 fw-bold">{{ Formato::cantidad($existencias->flatten()->sum('cantidad')) }} <small class="fs-6 text-muted">{{ $unidad }}</small></div>
                    <div class="small text-muted">
                        Costo promedio: <strong>{{ Formato::moneda($producto->costo_valuacion) }}</strong><br>
                        Mínimo: {{ Formato::cantidad($producto->stock_minimo) }}
                        @if ($producto->punto_reposicion !== null) · Reposición: {{ Formato::cantidad($producto->punto_reposicion) }} @endif
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Por depósito</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                        @forelse ($existencias as $filas)
                            @php($deposito = $filas->first()->deposito)
                            <tr class="table-light">
                                <td class="fw-semibold">{{ $deposito?->nombre }}</td>
                                <td class="text-end fw-semibold">{{ Formato::cantidad($filas->sum('cantidad')) }} {{ $unidad }}</td>
                            </tr>
                            @if ($filas->count() > 1 || $filas->first()->ubicacion_id)
                                @foreach ($filas as $e)
                                    <tr>
                                        <td class="ps-4 small"><i class="bi bi-geo text-muted"></i> {{ $e->ubicacion?->codigo ?? 'Sin ubicar' }}</td>
                                        <td class="text-end small">{{ Formato::cantidad($e->cantidad) }}</td>
                                    </tr>
                                @endforeach
                            @endif
                        @empty
                            <tr><td class="text-muted">Sin stock en tus depósitos.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Filtros --}}
    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Depósito</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-5 col-md-3">
                <label class="form-label small mb-1">Desde</label>
                <input type="date" name="desde" class="form-control" value="{{ $filtros['desde'] }}">
            </div>
            <div class="col-5 col-md-3">
                <label class="form-label small mb-1">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="{{ $filtros['hasta'] }}">
            </div>
            <div class="col-2 col-md-2 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Fecha</th>
                    <th>Movimiento</th>
                    <th>Depósito</th>
                    <th>Ubicación</th>
                    <th class="text-end">Entrada</th>
                    <th class="text-end">Salida</th>
                    <th class="text-end">Saldo en depósito</th>
                    <th class="text-end">Costo unit.</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($items as $item)
                    @php($mov = $item->movimiento)
                    <tr class="{{ $mov->estaAnulado() ? 'text-decoration-line-through text-muted' : '' }}">
                        <td class="small text-nowrap">{{ $item->fecha->format('d/m/Y H:i') }}</td>
                        <td>
                            <a href="{{ route('inventario.movimientos.show', $mov) }}" class="text-decoration-none">
                                <span class="badge text-bg-{{ $mov->color }}">{{ $mov->tipo_nombre }}</span>
                                <span class="small">N° {{ $mov->numero_texto }}</span>
                            </a>
                            @if ($mov->motivo || $mov->comprobante)
                                <div class="small text-muted">{{ collect([$mov->motivo?->nombre, $mov->comprobante])->filter()->implode(' · ') }}</div>
                            @endif
                        </td>
                        <td class="small">{{ $item->deposito?->nombre }}</td>
                        <td class="small">{{ $item->ubicacion?->codigo }}</td>
                        <td class="text-end text-success">{{ $item->entrada ? Formato::cantidad($item->entrada) : '' }}</td>
                        <td class="text-end text-danger">{{ $item->salida ? Formato::cantidad($item->salida) : '' }}</td>
                        <td class="text-end fw-semibold">{{ Formato::cantidad($item->saldo) }}</td>
                        <td class="text-end small">{{ Formato::moneda($item->costo_unitario) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No hay movimientos para los filtros elegidos.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($items->hasPages())
            <div class="card-footer bg-white">{{ $items->links() }}</div>
        @endif
    </div>
@endsection
