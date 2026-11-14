{{-- resources/views/inventario/toma-planilla.blade.php (planilla para contar en papel) --}}
@use('App\Support\Formato')
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planilla de conteo N° {{ $toma->numero_texto }}</title>
    <style>
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; margin: 20px; }
        h1 { font-size: 16px; margin: 0; }
        .cabecera { display: flex; justify-content: space-between; border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 5px 6px; vertical-align: top; }
        th { background: #eee; text-align: left; }
        .num { text-align: right; white-space: nowrap; }
        .blanco { width: 110px; }
        .grupo td { background: #f5f5f5; font-weight: bold; }
        .firmas { display: flex; gap: 40px; margin-top: 50px; }
        .firmas div { flex: 1; border-top: 1px solid #000; text-align: center; padding-top: 4px; }
        .acciones { margin-bottom: 12px; }
        @media print { .acciones { display: none; } tr { page-break-inside: avoid; } }
    </style>
</head>
<body>
    <div class="acciones">
        <button onclick="window.print()">Imprimir</button>
        @if ($muestraTeorico)
            <a href="{{ route('inventario.tomas.planilla', [$toma, 'ciega' => 1]) }}">Versión sin cantidades del sistema</a>
        @endif
    </div>

    <div class="cabecera">
        <div>
            <h1>{{ $empresa?->razon_social }}</h1>
            <div>Planilla de conteo · Toma N° {{ $toma->numero_texto }}</div>
            <div>{{ $toma->deposito?->nombre }} · {{ $toma->alcance }}{{ $toma->descripcion ? ' · ' . $toma->descripcion : '' }}</div>
        </div>
        <div style="text-align: right;">
            <div>Fecha: ____/____/________</div>
            <div style="margin-top: 6px;">Hoja ____ de ____</div>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>SKU</th>
            <th>Producto</th>
            <th>Lote</th>
            <th>Un.</th>
            @if ($muestraTeorico)<th class="num">Sistema</th>@endif
            <th class="blanco">Contado</th>
            <th class="blanco">Reconteo</th>
        </tr>
        </thead>
        <tbody>
        @php($grupoActual = false)
        @foreach ($items as $item)
            @php($grupo = $item->ubicacion?->codigo ?? 'Sin ubicar')
            @if ($toma->deposito?->usa_ubicaciones && $grupo !== $grupoActual)
                <tr class="grupo"><td colspan="{{ $muestraTeorico ? 7 : 6 }}">Ubicación: {{ $grupo }}</td></tr>
                @php($grupoActual = $grupo)
            @endif
            <tr>
                <td>{{ $item->producto?->sku }}</td>
                <td>{{ $item->producto?->nombre }}{{ $item->producto?->usa_series ? ' (anotar series)' : '' }}</td>
                <td>{{ $item->lote_texto ?? '' }}</td>
                <td>{{ $item->producto?->unidad?->abreviatura }}</td>
                @if ($muestraTeorico)<td class="num">{{ Formato::cantidad($item->teorico) }}</td>@endif
                <td></td>
                <td></td>
            </tr>
        @endforeach
        @for ($i = 0; $i < 8; $i++)
            <tr><td>&nbsp;</td><td></td><td></td><td></td>@if ($muestraTeorico)<td></td>@endif<td></td><td></td></tr>
        @endfor
        </tbody>
    </table>

    <div class="firmas">
        <div>Contó</div>
        <div>Controló</div>
        <div>Encargado</div>
    </div>
</body>
</html>
