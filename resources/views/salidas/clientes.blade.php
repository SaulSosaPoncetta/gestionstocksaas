{{-- resources/views/salidas/clientes.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Clientes')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">Clientes</h1>
        @unless ($maestroExterno)
            <a href="{{ route('salidas.clientes.create') }}" class="btn btn-stk"><i class="bi bi-plus-lg"></i> Nuevo cliente</a>
        @endunless
    </div>

    @if ($maestroExterno)
        <div class="alert alert-info small"><i class="bi bi-plug"></i> Los clientes se administran en <strong>{{ $maestroExterno }}</strong> y llegan sincronizados.</div>
    @endif

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2">
            <div class="col-md-10"><input type="search" name="q" class="form-control" placeholder="Razón social, nombre o documento" value="{{ $buscar }}"></div>
            <div class="col-md-2 d-grid"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>Cliente</th><th>Documento</th><th>Contacto</th><th class="text-center">Reservas activas</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($clientes as $c)
                    <tr>
                        <td>
                            <a href="{{ route('salidas.clientes.show', $c) }}" class="fw-semibold text-decoration-none">{{ $c->nombre }}</a>
                            @if ($c->nombre_fantasia)<div class="small text-muted">{{ $c->razon_social }}</div>@endif
                        </td>
                        <td class="small">{{ $c->documento ? $c->documento_tipo . ' ' . $c->documento : '—' }}</td>
                        <td class="small">
                            {{ $c->contacto }}
                            @if ($c->telefono)<div><i class="bi bi-telephone"></i> {{ $c->telefono }}</div>@endif
                        </td>
                        <td class="text-center">{{ $c->reservas_activas_count ?: '' }}</td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('salidas.remitos.create', ['cliente' => $c->id]) }}" class="btn btn-sm btn-outline-secondary" title="Nuevo remito"><i class="bi bi-truck"></i></a>
                            <a href="{{ route('salidas.clientes.show', $c) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-5"><i class="bi bi-people fs-1 d-block mb-2"></i>No hay clientes.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($clientes->hasPages())<div class="card-footer bg-white">{{ $clientes->links() }}</div>@endif
    </div>
@endsection
