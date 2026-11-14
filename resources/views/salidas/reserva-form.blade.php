{{-- resources/views/salidas/reserva-form.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Nueva reserva')

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('salidas.reservas.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-bookmark-plus"></i> Nueva reserva</h1>
            <span class="text-muted small">La mercadería no sale del depósito, pero deja de estar disponible para otras salidas hasta que la entregues o la liberes.</span>
        </div>
    </div>

    <form method="POST" action="{{ route('salidas.reservas.store') }}" id="formReserva">
        @csrf
        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-4">
                    <label class="form-label">Depósito *</label>
                    <select name="deposito_id" id="deposito" class="form-select" required>
                        @foreach ($depositos as $d)
                            <option value="{{ $d->id }}" @selected($depositoInicial === $d->id)>{{ $d->etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label d-flex justify-content-between">
                        Cliente
                        <a href="{{ route('salidas.clientes.create', ['volver' => url()->current()]) }}" class="small">+ nuevo</a>
                    </label>
                    <select name="cliente_id" class="form-select">
                        <option value="">Sin cliente</option>
                        @foreach ($clientes as $c)
                            <option value="{{ $c->id }}" @selected((string) old('cliente_id', request('cliente')) === (string) $c->id)>{{ $c->nombre }}{{ $c->documento ? ' · ' . $c->documento : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Vence (se libera sola)</label>
                    <input type="datetime-local" name="vence_at" class="form-control" value="{{ old('vence_at') }}" min="{{ now()->format('Y-m-d\TH:i') }}">
                    <div class="form-text">Vacío = no vence.</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones') }}" placeholder="Ej.: seña de $ 10.000, retira el viernes">
                </div>
            </div>
        </div>

        @include('salidas.partials.selector-productos', ['selector' => ['lineas' => $lineasPrevias]])

        <div class="mt-3"><button class="btn btn-stk" id="btnGuardar"><i class="bi bi-bookmark-check"></i> Reservar</button></div>
    </form>
@endsection

@push('scripts')
<script>
    document.getElementById('formReserva').addEventListener('submit', (e) => {
        if (!window.selectorTieneLineas()) { e.preventDefault(); alert('Agregá al menos un producto.'); return; }
        document.getElementById('btnGuardar').disabled = true;
    });
</script>
@endpush
