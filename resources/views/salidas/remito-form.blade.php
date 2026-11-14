{{-- resources/views/salidas/remito-form.blade.php --}}
@extends('layouts.app')

@section('titulo', 'Nuevo remito')

@section('contenido')
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ $reserva ? route('salidas.reservas.show', $reserva) : route('salidas.remitos.index') }}" class="btn btn-light"><i class="bi bi-arrow-left"></i></a>
        <div>
            <h1 class="h4 mb-0"><i class="bi bi-truck"></i> Nuevo remito de entrega</h1>
            <span class="text-muted small">
                @if ($reserva)
                    Entrega de la reserva N° {{ $reserva->numero_texto }}{{ $reserva->cliente ? ' · ' . $reserva->cliente->nombre : '' }}. Podés entregar menos de lo reservado: el resto sigue reservado.
                @else
                    Al confirmar, la mercadería sale del stock del depósito.
                @endif
            </span>
        </div>
    </div>

    <form method="POST" action="{{ route('salidas.remitos.store') }}" id="formRemito">
        @csrf
        @if ($reserva)<input type="hidden" name="reserva_id" value="{{ $reserva->id }}">@endif

        <div class="card mb-3">
            <div class="card-body row g-3">
                <div class="col-md-3">
                    <label class="form-label">Depósito *</label>
                    <select name="deposito_id" id="deposito" class="form-select" required @disabled($reserva)>
                        @foreach ($depositos as $d)
                            <option value="{{ $d->id }}" @selected($depositoInicial === $d->id)>{{ $d->etiqueta }}</option>
                        @endforeach
                    </select>
                    @if ($reserva)<input type="hidden" name="deposito_id" value="{{ $reserva->deposito_id }}">@endif
                </div>
                <div class="col-md-3">
                    <label class="form-label d-flex justify-content-between">
                        Cliente
                        @unless ($reserva)<a href="{{ route('salidas.clientes.create', ['volver' => url()->current()]) }}" class="small">+ nuevo</a>@endunless
                    </label>
                    @php($clienteInicial = (string) old('cliente_id', $reserva?->cliente_id ?? request('cliente')))
                    <select name="cliente_id" id="cliente" class="form-select">
                        <option value="">Consumidor final / sin cliente</option>
                        @foreach ($clientes as $c)
                            <option value="{{ $c->id }}" data-direccion="{{ $c->direccion_completa }}" @selected($clienteInicial === (string) $c->id)>{{ $c->nombre }}{{ $c->documento ? ' · ' . $c->documento : '' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Motivo</label>
                    <select name="motivo_id" class="form-select">
                        <option value="">Venta</option>
                        @foreach ($motivos as $m)
                            @continue($m->nombre === 'Venta')
                            <option value="{{ $m->id }}" @selected((string) old('motivo_id') === (string) $m->id)>{{ $m->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fecha *</label>
                    <input type="date" name="fecha" class="form-control" value="{{ old('fecha', now()->toDateString()) }}" max="{{ now()->toDateString() }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Dirección de entrega</label>
                    <input type="text" name="direccion_entrega" id="direccion" class="form-control" maxlength="255" value="{{ old('direccion_entrega') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Transportista</label>
                    <input type="text" name="transportista" class="form-control" maxlength="150" value="{{ old('transportista') }}" placeholder="Ej.: retira el cliente">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Comprobante de venta</label>
                    <input type="text" name="comprobante_venta" class="form-control" maxlength="60" value="{{ old('comprobante_venta') }}" placeholder="Ej.: FC A 0001-00001234">
                </div>
                <div class="col-12">
                    <label class="form-label">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="2000" value="{{ old('observaciones') }}">
                </div>
            </div>
        </div>

        @include('salidas.partials.selector-productos', ['selector' => ['ubicaciones' => true, 'series' => true, 'lineas' => $lineasPrevias]])

        <div class="mt-3"><button class="btn btn-stk" id="btnGuardar"><i class="bi bi-check2-circle"></i> Emitir remito</button></div>
    </form>
@endsection

@push('scripts')
<script>
    (() => {
        const cliente = document.getElementById('cliente');
        const direccion = document.getElementById('direccion');
        const completar = () => {
            const d = cliente.selectedOptions[0]?.dataset.direccion || '';
            if (!direccion.dataset.editada && d) direccion.value = d;
        };
        direccion.addEventListener('input', () => { direccion.dataset.editada = '1'; });
        cliente.addEventListener('change', () => { delete direccion.dataset.editada; direccion.value = ''; completar(); });
        if (!direccion.value) completar();

        document.getElementById('formRemito').addEventListener('submit', (e) => {
            if (!window.selectorTieneLineas()) { e.preventDefault(); alert('Agregá al menos un producto.'); return; }
            if (!confirm('¿Emitir el remito? La mercadería sale del stock.')) { e.preventDefault(); return; }
            document.getElementById('btnGuardar').disabled = true;
        });
    })();
</script>
@endpush
