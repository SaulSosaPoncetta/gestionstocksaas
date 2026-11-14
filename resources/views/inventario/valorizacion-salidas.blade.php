{{-- resources/views/inventario/valorizacion-salidas.blade.php (costo de lo que salió en un período) --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Costo de salidas')

@section('contenido')
    @php
        $t = $datos['totales'];
        $pct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.') . ' %';
        $nombreMotivo = $motivoElegido ? ($datos['motivos']->first(fn ($m) => (int) $m['motivo_id'] === (int) $motivoElegido)['nombre'] ?? 'el motivo elegido') : 'todas las salidas';
    @endphp

    @include('inventario.partials.valorizacion-pestanias')

    <form method="GET" class="card card-body mb-3 no-imprimir">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-0">Desde</label>
                <input type="date" name="desde" class="form-control" value="{{ $desde->toDateString() }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Hasta</label>
                <input type="date" name="hasta" class="form-control" value="{{ $hasta->toDateString() }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Depósito</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Categoría</label>
                <select name="categoria_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach ($categorias as $c)
                        <option value="{{ $c->id }}" @selected($filtros['categoria_id'] === $c->id)>{{ str_repeat('— ', $c->nivel) }}{{ $c->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-0">Marca</label>
                <select name="marca_id" class="form-select">
                    <option value="">Todas</option>
                    @foreach ($marcas as $m)
                        <option value="{{ $m->id }}" @selected($filtros['marca_id'] === $m->id)>{{ $m->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <input type="hidden" name="motivo_id" value="{{ $motivoElegido }}">
                <button class="btn btn-outline-secondary w-100"><i class="bi bi-funnel"></i> Ver</button>
            </div>
            <div class="col-12 small text-muted">
                Atajos:
                <a href="{{ request()->fullUrlWithQuery(['desde' => today()->startOfMonth()->toDateString(), 'hasta' => today()->toDateString()]) }}">este mes</a> ·
                <a href="{{ request()->fullUrlWithQuery(['desde' => today()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'hasta' => today()->subMonthNoOverflow()->endOfMonth()->toDateString()]) }}">mes anterior</a> ·
                <a href="{{ request()->fullUrlWithQuery(['desde' => today()->startOfYear()->toDateString(), 'hasta' => today()->toDateString()]) }}">este año</a>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between">
                    <span class="fw-semibold">Por motivo</span>
                    <span class="small text-muted">{{ $desde->format('d/m/Y') }} al {{ $hasta->format('d/m/Y') }}</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <a href="{{ request()->fullUrlWithQuery(['motivo_id' => '']) }}" class="list-group-item list-group-item-action d-flex justify-content-between {{ $motivoElegido === null ? 'active' : '' }}">
                        <span>Todas las salidas y ajustes</span><span class="fw-semibold">{{ Formato::moneda($t['costo']) }}</span>
                    </a>
                    @forelse ($datos['motivos'] as $m)
                        <a href="{{ $m['motivo_id'] ? request()->fullUrlWithQuery(['motivo_id' => $m['motivo_id']]) : '#' }}"
                           class="list-group-item list-group-item-action d-flex justify-content-between {{ $m['motivo_id'] && (int) $motivoElegido === (int) $m['motivo_id'] ? 'active' : '' }}">
                            <span>{{ $m['nombre'] }} <span class="opacity-75">({{ $m['tipo'] === 'ajuste' ? 'ajuste' : 'egreso' }})</span></span>
                            <span class="fw-semibold {{ $m['costo'] < 0 ? 'text-success' : '' }}">{{ Formato::moneda($m['costo']) }}</span>
                        </a>
                    @empty
                        <li class="list-group-item text-muted">No hubo salidas en el período.</li>
                    @endforelse
                </ul>
                <div class="card-footer bg-white small text-muted">Los ajustes muestran el neto: si sobró mercadería, el valor es negativo (en verde).</div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="row g-3">
                <div class="col-sm-4">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">Costo ({{ $nombreMotivo }})</div>
                        <div class="h5 mb-0">{{ Formato::moneda($t['costo_detalle']) }}</div>
                    </div></div>
                </div>
                <div class="col-sm-4">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">Venta estimada</div>
                        <div class="h5 mb-0">{{ Formato::moneda($t['venta_estimada']) }}</div>
                    </div></div>
                </div>
                <div class="col-sm-4">
                    <div class="card h-100"><div class="card-body">
                        <div class="text-muted small">Margen estimado</div>
                        <div class="h5 mb-0 {{ $t['margen'] < 0 ? 'text-danger' : 'text-success' }}">{{ Formato::moneda($t['margen']) }}</div>
                    </div></div>
                </div>
                <div class="col-12">
                    <div class="alert alert-light border small mb-0">
                        <i class="bi bi-info-circle"></i> El <strong>costo</strong> es el real de cada salida (costo promedio de ese momento).
                        La <strong>venta y el margen son estimados</strong> a precio de lista actual: el precio real de cada venta está en el sistema de facturación
                        (con GestiónComercial integrado se va a poder ver el margen real).
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white fw-semibold">Detalle por producto · {{ $nombreMotivo }}</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Producto</th><th class="text-end">Cantidad</th><th class="text-end">Costo unit.</th><th class="text-end">Costo total</th><th class="text-end">Venta estimada</th><th class="text-end">Margen</th></tr>
                </thead>
                <tbody>
                @forelse ($datos['productos'] as $f)
                    @php($p = $f['producto'])
                    <tr>
                        <td>
                            <a href="{{ route('inventario.kardex', $p) }}" class="fw-semibold text-decoration-none">{{ $p->nombre }}</a>
                            <div class="small text-muted"><code>{{ $p->sku }}</code>{{ $p->categoria ? ' · ' . $p->categoria->nombre : '' }}</div>
                        </td>
                        <td class="text-end text-nowrap">{{ Formato::cantidad($f['cantidad']) }} {{ $p->unidad?->abreviatura }}</td>
                        <td class="text-end">{{ Formato::moneda($f['costo_unitario']) }}</td>
                        <td class="text-end fw-semibold">{{ Formato::moneda($f['costo']) }}</td>
                        <td class="text-end">{{ Formato::moneda($f['venta_estimada']) }}</td>
                        <td class="text-end {{ $f['margen'] < 0 ? 'text-danger' : 'text-success' }}">
                            {{ Formato::moneda($f['margen']) }}<div class="small text-muted">{{ $pct($f['margen_pct']) }}</div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5">Sin salidas para mostrar.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
