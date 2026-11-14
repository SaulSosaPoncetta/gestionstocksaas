{{-- resources/views/inventario/vencimientos.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Vencimientos')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Vencimientos</h1>
            <span class="text-muted small">Lotes con stock, ordenados por fecha de vencimiento.</span>
        </div>
        @if (auth()->user()->tieneRol('admin', 'encargado', 'operador'))
            <a href="{{ route('inventario.movimientos.create', 'egreso') }}" class="btn btn-outline-danger">
                <i class="bi bi-trash3"></i> Dar de baja vencidos
            </a>
        @endif
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-lg-3">
                <label class="form-label small mb-1">Buscar</label>
                <input type="search" name="q" class="form-control" placeholder="Producto, SKU o lote" value="{{ $filtros['q'] }}">
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small mb-1">Depósito</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-3">
                <label class="form-label small mb-1">Mostrar</label>
                <select name="estado" class="form-select">
                    @foreach ($estados as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label small mb-1">Aviso (días)</label>
                <input type="number" name="dias" class="form-control" min="1" max="3650" value="{{ $filtros['dias'] }}">
            </div>
            <div class="col-6 col-lg-1 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Vence</th>
                    <th>Producto</th>
                    <th>Lote</th>
                    <th>Depósito</th>
                    <th class="text-end">Cantidad</th>
                    <th class="text-end">Valor</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($filas as $fila)
                    @php
                        $lote = $fila->lote;
                        $dias = $lote?->diasParaVencer();
                        [$color, $texto] = match (true) {
                            $dias === null => ['secondary', 'Sin vencimiento'],
                            $dias < 0 => ['danger', 'Vencido hace ' . abs($dias) . ' días'],
                            $dias === 0 => ['danger', 'Vence hoy'],
                            $dias <= $filtros['dias'] => ['warning', "En {$dias} días"],
                            default => ['success', "En {$dias} días"],
                        };
                    @endphp
                    <tr>
                        <td class="text-nowrap">
                            <div class="fw-semibold">{{ $lote?->fecha_vencimiento?->format('d/m/Y') ?? '—' }}</div>
                            <span class="badge text-bg-{{ $color }}">{{ $texto }}</span>
                        </td>
                        <td>
                            <div class="fw-semibold">{{ $fila->producto?->nombre }}</div>
                            <div class="small text-muted"><code>{{ $fila->producto?->sku }}</code></div>
                        </td>
                        <td class="font-monospace">{{ $lote?->codigo }}</td>
                        <td class="small">{{ $fila->deposito?->nombre }}</td>
                        <td class="text-end fw-semibold">{{ Formato::cantidad($fila->cantidad) }} {{ $fila->producto?->unidad?->abreviatura }}</td>
                        <td class="text-end small">{{ Formato::moneda((float) $fila->cantidad * ($fila->producto?->costo_valuacion ?? 0)) }}</td>
                        <td class="text-end">
                            @if ($fila->producto)
                                <a href="{{ route('inventario.kardex', ['producto' => $fila->producto, 'deposito_id' => $fila->deposito_id]) }}" class="btn btn-sm btn-outline-secondary" title="Kardex">
                                    <i class="bi bi-journal-text"></i>
                                </a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-calendar-check fs-1 d-block mb-2"></i>
                            No hay lotes para los filtros elegidos.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($filas->hasPages())
            <div class="card-footer bg-white">{{ $filas->links() }}</div>
        @endif
    </div>
@endsection
