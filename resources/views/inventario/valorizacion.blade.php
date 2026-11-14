{{-- resources/views/inventario/valorizacion.blade.php (stock actual y a una fecha) --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Valorización')

@section('contenido')
    @php
        $historica = $pestania === 'historica';
        $t = $datos['totales'];
        $pct = fn ($v) => $v === null ? '—' : number_format($v, 1, ',', '.') . ' %';
    @endphp

    @include('inventario.partials.valorizacion-pestanias')

    <form method="GET" class="card card-body mb-3 no-imprimir">
        <div class="row g-2">
            @if ($historica)
                <div class="col-md-2">
                    <label class="form-label small mb-0">Al cierre del día</label>
                    <input type="date" name="fecha" class="form-control" value="{{ $fecha->toDateString() }}" max="{{ today()->toDateString() }}">
                </div>
            @endif
            <div class="col-md-{{ $historica ? 2 : 3 }}">
                <label class="form-label small mb-0">Depósito</label>
                <select name="deposito_id" class="form-select">
                    <option value="">Todos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
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
            <div class="col-md-{{ $historica ? 2 : 3 }}">
                <label class="form-label small mb-0">Producto</label>
                <input type="search" name="q" class="form-control" value="{{ $filtros['q'] }}" placeholder="Nombre o SKU">
            </div>
            <div class="col-md-1 d-grid align-items-end"><button class="btn btn-outline-secondary mt-auto"><i class="bi bi-funnel"></i></button></div>
            <div class="col-12">
                <div class="form-check form-check-inline small">
                    <input class="form-check-input" type="checkbox" name="incluir_ceros" value="1" id="incluirCeros" @checked($filtros['incluir_ceros'])>
                    <label class="form-check-label" for="incluirCeros">Incluir productos con stock en cero</label>
                </div>
            </div>
        </div>
    </form>

    @if ($historica)
        <div class="alert alert-light border small">
            <i class="bi bi-info-circle"></i> Stock reconstruido desde el kardex al cierre del <strong>{{ $fecha->format('d/m/Y') }}</strong>,
            valorizado al costo promedio que tenía cada producto ese día. Útil para el cierre contable de un mes.
        </div>
    @endif

    {{-- Totales --}}
    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body">
                <div class="text-muted small">Valor a costo</div>
                <div class="h4 mb-0">{{ Formato::moneda($t['valor_costo']) }}</div>
                <div class="small text-muted">{{ $t['productos'] }} productos</div>
            </div></div>
        </div>
        @unless ($historica)
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted small">Valor a precio de venta</div>
                    <div class="h4 mb-0">{{ Formato::moneda($t['valor_venta']) }}</div>
                    <div class="small text-muted">neto de IVA</div>
                </div></div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="card h-100"><div class="card-body">
                    <div class="text-muted small">Margen potencial</div>
                    <div class="h4 mb-0 text-success">{{ Formato::moneda($t['margen']) }}</div>
                    <div class="small text-muted">{{ $pct($t['margen_pct']) }} sobre venta</div>
                </div></div>
            </div>
        @endunless
        <div class="col-6 col-lg-3">
            <div class="card h-100"><div class="card-body small">
                <div class="text-muted">A revisar</div>
                <div class="{{ $t['negativos'] ? 'text-danger fw-semibold' : '' }}"><i class="bi bi-dash-circle"></i> {{ $t['negativos'] }} con stock negativo</div>
                <div class="{{ $t['sin_costo'] ? 'text-warning-emphasis fw-semibold' : '' }}"><i class="bi bi-question-circle"></i> {{ $t['sin_costo'] }} con stock y sin costo</div>
            </div></div>
        </div>
    </div>

    {{-- Agrupado --}}
    <div class="row g-3 mb-3">
        @foreach (['por_deposito' => 'Por depósito', 'por_categoria' => 'Por categoría', 'por_marca' => 'Por marca'] as $clave => $titulo)
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header bg-white fw-semibold">{{ $titulo }}</div>
                    <ul class="list-group list-group-flush small">
                        @forelse ($datos[$clave]->take(8) as $g)
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <span>{{ $clave === 'por_deposito' ? ($nombresDeposito[$g['id']] ?? 'Depósito #' . $g['id']) : $g['nombre'] }}</span>
                                    <span class="fw-semibold">{{ Formato::moneda($g['valor_costo']) }}</span>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="barra-part flex-grow-1"><div style="width: {{ max(0, min(100, $g['participacion'])) }}%"></div></div>
                                    <span class="text-muted" style="min-width: 46px; text-align: right;">{{ number_format($g['participacion'], 1, ',', '.') }}%</span>
                                </div>
                            </li>
                        @empty
                            <li class="list-group-item text-muted">Sin datos.</li>
                        @endforelse
                        @if ($datos[$clave]->count() > 8)
                            <li class="list-group-item text-muted">y {{ $datos[$clave]->count() - 8 }} más…</li>
                        @endif
                    </ul>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Detalle por producto --}}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Producto</th>
                    <th class="text-end">Cantidad</th>
                    <th class="text-end">Costo unit.</th>
                    <th class="text-end">Valor a costo</th>
                    @unless ($historica)
                        <th class="text-end">Precio venta</th>
                        <th class="text-end">Valor a venta</th>
                        <th class="text-end">Margen</th>
                    @endunless
                </tr>
                </thead>
                <tbody>
                @forelse ($filas as $f)
                    @php($p = $f['producto'])
                    <tr class="{{ $f['negativo'] ? 'table-danger' : '' }}">
                        <td>
                            <a href="{{ route('inventario.kardex', $p) }}" class="fw-semibold text-decoration-none">{{ $p->nombre }}</a>
                            @if ($p->trashed())<span class="badge text-bg-dark">eliminado</span>@endif
                            <div class="small text-muted">
                                <code>{{ $p->sku }}</code>{{ $p->categoria ? ' · ' . $p->categoria->nombre : '' }}
                                @if (count($f['depositos']) > 1)
                                    · {{ collect($f['depositos'])->map(fn ($c, $id) => ($nombresDeposito[$id] ?? '#' . $id) . ' ' . Formato::cantidad($c))->implode(' · ') }}
                                @endif
                            </div>
                        </td>
                        <td class="text-end text-nowrap">{{ Formato::cantidad($f['cantidad']) }} {{ $p->unidad?->abreviatura }}</td>
                        <td class="text-end {{ $f['sin_costo'] ? 'text-warning-emphasis' : '' }}">{{ Formato::moneda($f['costo']) }}</td>
                        <td class="text-end fw-semibold">{{ Formato::moneda($f['valor_costo']) }}</td>
                        @unless ($historica)
                            <td class="text-end">{{ Formato::moneda($f['precio']) }}</td>
                            <td class="text-end">{{ Formato::moneda($f['valor_venta']) }}</td>
                            <td class="text-end {{ ($f['margen'] ?? 0) < 0 ? 'text-danger' : 'text-success' }}">
                                {{ Formato::moneda($f['margen']) }}
                                <div class="small text-muted">{{ $pct($f['margen_pct']) }}</div>
                            </td>
                        @endunless
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-5">No hay stock para valorizar con esos filtros.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($filas->hasPages())<div class="card-footer bg-white no-imprimir">{{ $filas->links() }}</div>@endif
    </div>
@endsection
