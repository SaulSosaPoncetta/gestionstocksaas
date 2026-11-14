{{-- resources/views/inventario/tomas.blade.php --}}
@extends('layouts.app')
@use('App\Support\Formato')

@section('titulo', 'Inventario físico')

@section('contenido')
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h4 mb-0">Inventario físico</h1>
            <span class="text-muted small">Contá la mercadería y el sistema ajusta solo las diferencias al cerrar.</span>
        </div>
        @if (auth()->user()->tieneRol('admin', 'encargado'))
            <a href="{{ route('inventario.tomas.create') }}" class="btn btn-stk"><i class="bi bi-clipboard-check"></i> Nueva toma</a>
        @endif
    </div>

    <form method="GET" class="card card-body mb-3">
        <div class="row g-2">
            <div class="col-md-5">
                <select name="estado" class="form-select">
                    <option value="todas">Todas</option>
                    @foreach ($estados as $valor => $texto)
                        <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-5">
                <select name="deposito_id" class="form-select">
                    <option value="">Todos los depósitos</option>
                    @foreach ($depositos as $d)
                        <option value="{{ $d->id }}" @selected($filtros['deposito_id'] === $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2 d-grid"><button class="btn btn-outline-secondary"><i class="bi bi-funnel"></i></button></div>
        </div>
    </form>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr><th>N°</th><th>Depósito / alcance</th><th>Inicio</th><th style="width: 180px;">Avance</th><th class="text-end">Faltante</th><th class="text-end">Sobrante</th><th>Estado</th><th></th></tr>
                </thead>
                <tbody>
                @forelse ($tomas as $t)
                    @php($avance = $t->items_count ? (int) floor($t->contados_count * 100 / $t->items_count) : 100)
                    <tr>
                        <td class="fw-semibold"><a href="{{ route('inventario.tomas.show', $t) }}" class="text-decoration-none">{{ $t->numero_texto }}</a></td>
                        <td>
                            {{ $t->deposito?->nombre }}
                            <div class="small text-muted">{{ $t->alcance }}{{ $t->descripcion ? ' · ' . $t->descripcion : '' }}</div>
                        </td>
                        <td class="small">{{ $t->created_at->format('d/m/Y H:i') }}<div class="text-muted">{{ $t->usuario?->name }}</div></td>
                        <td>
                            <div class="small">{{ $t->contados_count }} / {{ $t->items_count }}</div>
                            <div class="progress" style="height: 5px;"><div class="progress-bar bg-success" style="width: {{ $avance }}%"></div></div>
                        </td>
                        <td class="text-end small text-danger">{{ $t->estado === 'cerrada' && $t->valor_faltante > 0 ? Formato::moneda($t->valor_faltante) : '' }}</td>
                        <td class="text-end small text-success">{{ $t->estado === 'cerrada' && $t->valor_sobrante > 0 ? Formato::moneda($t->valor_sobrante) : '' }}</td>
                        <td>
                            <span class="badge text-bg-{{ $t->color }}">{{ $t->estado_nombre }}</span>
                            @if ($t->estaEnCurso() && $t->congela)<span class="badge text-bg-info" title="Depósito congelado"><i class="bi bi-snow"></i></span>@endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('inventario.tomas.show', $t) }}" class="btn btn-sm {{ $t->estaEnCurso() ? 'btn-stk' : 'btn-outline-secondary' }}">
                                {!! $t->estaEnCurso() ? '<i class="bi bi-123"></i> Contar' : '<i class="bi bi-eye"></i>' !!}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-5"><i class="bi bi-clipboard-check fs-1 d-block mb-2"></i>Todavía no se hizo ninguna toma de inventario.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($tomas->hasPages())<div class="card-footer bg-white">{{ $tomas->links() }}</div>@endif
    </div>
@endsection
