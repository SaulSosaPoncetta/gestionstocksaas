{{-- resources/views/inventario/partials/valorizacion-pestanias.blade.php --}}
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h4 mb-0">Valorización</h1>
        <span class="text-muted small">Cuánto vale el stock y cuánto costó lo que salió. Costos a promedio ponderado; precios netos de IVA.</span>
    </div>
    <div class="d-flex gap-2 no-imprimir">
        <a href="{{ request()->fullUrlWithQuery(['exportar' => 'csv', 'page' => null]) }}" class="btn btn-outline-secondary"><i class="bi bi-filetype-csv"></i> Exportar</a>
        <button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i></button>
    </div>
</div>

<ul class="nav nav-tabs mb-3 no-imprimir">
    <li class="nav-item"><a class="nav-link {{ $pestania === 'actual' ? 'active' : '' }}" href="{{ route('inventario.valorizacion.index') }}"><i class="bi bi-cash-stack"></i> Stock actual</a></li>
    <li class="nav-item"><a class="nav-link {{ $pestania === 'historica' ? 'active' : '' }}" href="{{ route('inventario.valorizacion.historica') }}"><i class="bi bi-calendar3"></i> A una fecha</a></li>
    <li class="nav-item"><a class="nav-link {{ $pestania === 'salidas' ? 'active' : '' }}" href="{{ route('inventario.valorizacion.salidas') }}"><i class="bi bi-graph-down-arrow"></i> Costo de salidas</a></li>
</ul>

@push('styles')
<style>
    .barra-part { height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; }
    .barra-part > div { height: 100%; background: var(--bs-primary); }
    @media print {
        aside.sidebar, header, .no-imprimir, .btn, .alert-dismissible { display: none !important; }
        main { padding: 0 !important; }
        .card { box-shadow: none !important; border: 1px solid #ccc !important; }
        body { background: #fff !important; }
    }
</style>
@endpush
