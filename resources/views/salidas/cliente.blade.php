{{-- resources/views/salidas/cliente.blade.php --}}
@extends('layouts.app')

@section('titulo', $cliente->nombre)

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
        <a href="{{ route('salidas.clientes.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div class="me-auto">
            <h1 class="h4 mb-0">{{ $cliente->nombre }}</h1>
            <span class="text-muted small">{{ $cliente->razon_social }} {{ $cliente->documento ? '· ' . $cliente->documento_tipo . ' ' . $cliente->documento : '' }}</span>
        </div>
        <a href="{{ route('salidas.reservas.create', ['cliente' => $cliente->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-bookmark"></i> Reservar</a>
        <a href="{{ route('salidas.remitos.create', ['cliente' => $cliente->id]) }}" class="btn btn-stk"><i class="bi bi-truck"></i> Nuevo remito</a>
        @unless ($maestroExterno)
            <a href="{{ route('salidas.clientes.edit', $cliente) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil"></i></a>
        @endunless
    </div>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Datos</div>
                <ul class="list-group list-group-flush small">
                    @if ($cliente->contacto)<li class="list-group-item"><i class="bi bi-person"></i> {{ $cliente->contacto }}</li>@endif
                    @if ($cliente->telefono)<li class="list-group-item"><i class="bi bi-telephone"></i> {{ $cliente->telefono }}</li>@endif
                    @if ($cliente->email)<li class="list-group-item"><i class="bi bi-envelope"></i> {{ $cliente->email }}</li>@endif
                    @if ($cliente->direccion_completa)<li class="list-group-item"><i class="bi bi-geo-alt"></i> {{ $cliente->direccion_completa }}</li>@endif
                    @if ($cliente->observaciones)<li class="list-group-item">{{ $cliente->observaciones }}</li>@endif
                </ul>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Reservas</div>
                <ul class="list-group list-group-flush small">
                    @forelse ($reservas as $r)
                        <li class="list-group-item d-flex justify-content-between">
                            <a href="{{ route('salidas.reservas.show', $r) }}">N° {{ $r->numero_texto }}</a>
                            <span class="badge text-bg-{{ $r->color }}">{{ $r->estado_nombre }}</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Sin reservas.</li>
                    @endforelse
                </ul>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header bg-white fw-semibold">Últimos remitos</div>
                <ul class="list-group list-group-flush small">
                    @forelse ($remitos as $r)
                        <li class="list-group-item d-flex justify-content-between">
                            <a href="{{ route('salidas.remitos.show', $r) }}">N° {{ $r->numero_texto }}</a>
                            <span class="text-muted">{{ $r->fecha->format('d/m/Y') }} · {{ $r->items_count }} prod. @if ($r->estaAnulado())<span class="badge text-bg-dark">Anulado</span>@endif</span>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">Sin remitos.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
@endsection
