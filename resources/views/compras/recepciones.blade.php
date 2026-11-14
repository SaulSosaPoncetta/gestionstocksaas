{{-- resources/views/compras/recepciones.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Recepciones')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h1 class="h4 mb-0">Recepciones de mercadería</h1>
        <a href="{{ route('compras.ordenes.index', ['estado' => 'abiertas']) }}" class="btn btn-success"><i class="bi bi-box-arrow-in-down"></i> Recibir una orden</a>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>N°</th><th>Fecha</th><th>Proveedor</th><th>Orden</th><th>Depósito</th><th>Remito</th><th class="text-center">Productos</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($recepciones as $r)
                    <tr class="{{ $r->estaAnulada() ? 'text-muted' : '' }}">
                        <td class="fw-semibold">
                            <a href="{{ route('compras.recepciones.show', $r) }}" class="text-decoration-none">{{ $r->numero_texto }}</a>
                            @if ($r->estaAnulada())<span class="badge text-bg-dark">Anulada</span>@endif
                        </td>
                        <td class="small">{{ $r->fecha->format('d/m/Y') }}</td>
                        <td>{{ $r->proveedor?->nombre }}</td>
                        <td class="small"><a href="{{ route('compras.ordenes.show', $r->orden) }}">N° {{ $r->orden?->numero_texto }}</a></td>
                        <td class="small">{{ $r->deposito?->nombre }}</td>
                        <td class="small">{{ $r->remito }}</td>
                        <td class="text-center">{{ $r->items_count }}</td>
                        <td class="text-end"><a href="{{ route('compras.recepciones.show', $r) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-box-arrow-in-down fs-1 d-block mb-2"></i>Todavía no hay recepciones.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($recepciones->hasPages())
            <div class="card-footer bg-white">{{ $recepciones->links() }}</div>
        @endif
    </div>
@endsection
