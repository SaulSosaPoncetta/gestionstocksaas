{{-- resources/views/compras/proveedores.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Proveedores')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">Proveedores</h1>
        @unless ($maestroExterno)
            <a href="{{ route('compras.proveedores.create') }}" class="btn btn-stk"><i class="bi bi-plus-lg"></i> Nuevo proveedor</a>
        @endunless
    </div>

    @if ($maestroExterno)
        <div class="alert alert-info small">
            <i class="bi bi-plug"></i> Los proveedores se administran en <strong>{{ $maestroExterno }}</strong> y llegan sincronizados.
            Acá podés asociarles productos y ver sus órdenes.
        </div>
    @endif

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-7">
                <input type="search" name="q" class="form-control" placeholder="Razón social, nombre de fantasía o CUIT" value="{{ $buscar }}">
            </div>
            <div class="col-8 col-md-3">
                <select name="estado" class="form-select">
                    <option value="activos" @selected($estado === 'activos')>Activos</option>
                    <option value="inactivos" @selected($estado === 'inactivos')>Inactivos</option>
                    <option value="todos" @selected($estado === 'todos')>Todos</option>
                </select>
            </div>
            <div class="col-4 col-md-2 d-grid">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Proveedor</th>
                    <th>CUIT</th>
                    <th>Contacto</th>
                    <th class="text-center">Productos</th>
                    <th class="text-center">Órdenes abiertas</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($proveedores as $p)
                    <tr class="{{ $p->activo ? '' : 'text-muted' }}">
                        <td>
                            <a href="{{ route('compras.proveedores.show', $p) }}" class="fw-semibold text-decoration-none">{{ $p->nombre }}</a>
                            @if ($p->nombre_fantasia)<div class="small text-muted">{{ $p->razon_social }}</div>@endif
                            @unless ($p->activo)<span class="badge text-bg-secondary">Inactivo</span>@endunless
                        </td>
                        <td class="small">{{ $p->cuit }}</td>
                        <td class="small">
                            {{ $p->contacto }}
                            @if ($p->telefono)<div><i class="bi bi-telephone"></i> {{ $p->telefono }}</div>@endif
                            @if ($p->email)<div><i class="bi bi-envelope"></i> {{ $p->email }}</div>@endif
                        </td>
                        <td class="text-center">{{ $p->productos_count }}</td>
                        <td class="text-center">{{ $p->ordenes_abiertas_count ?: '' }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('compras.ordenes.create', ['proveedor_id' => $p->id]) }}" class="btn btn-sm btn-outline-secondary" title="Nueva orden"><i class="bi bi-receipt"></i></a>
                            <a href="{{ route('compras.proveedores.show', $p) }}" class="btn btn-sm btn-outline-secondary" title="Ver"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-person-vcard fs-1 d-block mb-2"></i>No hay proveedores.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($proveedores->hasPages())
            <div class="card-footer bg-white">{{ $proveedores->links() }}</div>
        @endif
    </div>
@endsection
