<?php

namespace App\Services;

use App\Events\StockActualizado;
use App\Models\Deposito;
use App\Models\Existencia;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\Numerador;
use App\Models\Producto;
use App\Models\Remito;
use App\Models\Reserva;
use App\Models\ReservaItem;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salidas a clientes.
 *
 * Reservas: comprometen stock sin sacarlo. El stock real no cambia, baja el DISPONIBLE (real − reservado),
 *           y ninguna salida común puede tomar lo reservado.
 * Remitos:  la entrega física. Puede ser de una reserva (libera lo reservado y lo saca) o directa.
 *
 * Las dos admiten referencia_externa (ej. mercado:pedido:1532) para que Mercado o Comercial
 * no dupliquen si mandan lo mismo dos veces.
 */
class SalidaService
{
    public const MOTIVO_ENTREGA = 'Venta';

    public function __construct(protected StockService $stock)
    {
    }

    /* =========================================================
       Reservas
       ========================================================= */

    /**
     * @param  array  $lineas  [['producto_id', 'cantidad', 'lote_id'?], …]
     * @param  array  $datos   cliente_id, vence_at, observaciones, origen, referencia_externa
     */
    public function reservar(int $depositoId, array $lineas, array $datos = [], ?User $usuario = null): Reserva
    {
        $referencia = filled($datos['referencia_externa'] ?? null) ? (string) $datos['referencia_externa'] : null;

        if ($referencia && ($existente = Reserva::with('items')->where('referencia_externa', $referencia)->first())) {
            return $existente;
        }

        $deposito = Deposito::activos()->find($depositoId);
        if (! $deposito) {
            throw ValidationException::withMessages(['deposito_id' => 'El depósito no existe o está inactivo.']);
        }

        if ($usuario && ! $usuario->puedeOperarDeposito($deposito->id)) {
            throw ValidationException::withMessages(['deposito_id' => "No tenés permiso para operar {$deposito->nombre}."]);
        }

        // Una línea por producto + lote
        $agrupadas = [];
        foreach ($lineas as $linea) {
            $clave = ((int) ($linea['producto_id'] ?? 0)) . '-' . ((int) ($linea['lote_id'] ?? 0));
            $agrupadas[$clave] ??= ['producto_id' => (int) ($linea['producto_id'] ?? 0), 'lote_id' => ! empty($linea['lote_id']) ? (int) $linea['lote_id'] : null, 'cantidad' => 0];
            $agrupadas[$clave]['cantidad'] += abs((float) ($linea['cantidad'] ?? 0));
        }
        $lineas = array_values(array_filter($agrupadas, fn ($l) => $l['cantidad'] > StockService::TOLERANCIA));

        if (! $lineas) {
            throw ValidationException::withMessages(['lineas' => 'Agregá al menos un producto con cantidad.']);
        }

        try {
            return DB::transaction(function () use ($deposito, $lineas, $datos, $usuario, $referencia) {
                $productos = $this->bloquearProductos(array_column($lineas, 'producto_id'));
                $errores = [];

                foreach ($lineas as $linea) {
                    $producto = $productos->get($linea['producto_id']);

                    if (! $producto || ! $producto->manejaStock()) {
                        $errores[] = 'Uno de los productos no existe o no controla stock.';
                        continue;
                    }

                    if (! $producto->unidad?->permite_decimales && abs($linea['cantidad'] - round($linea['cantidad'])) > StockService::TOLERANCIA) {
                        $errores[] = "{$producto->nombre}: la unidad no admite decimales.";
                        continue;
                    }

                    $disponible = $this->stock->disponible($producto->id, $deposito->id, $linea['lote_id']);

                    if ($linea['cantidad'] > $disponible + StockService::TOLERANCIA) {
                        $errores[] = "{$producto->nombre}: hay " . Formato::cantidad(max(0, $disponible)) . ' '
                            . ($producto->unidad?->abreviatura ?? '') . " disponibles en {$deposito->nombre} y se quieren reservar " . Formato::cantidad($linea['cantidad']) . '.';
                    }
                }

                if ($errores) {
                    throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores))]);
                }

                $reserva = Reserva::create([
                    'numero' => Numerador::siguiente('reserva'),
                    'cliente_id' => $datos['cliente_id'] ?? null,
                    'deposito_id' => $deposito->id,
                    'estado' => Reserva::ACTIVA,
                    'origen' => $datos['origen'] ?? ($usuario ? 'manual' : 'api'),
                    'referencia_externa' => $referencia,
                    'vence_at' => $datos['vence_at'] ?? null,
                    'observaciones' => $datos['observaciones'] ?? null,
                    'user_id' => $usuario?->id,
                ]);

                foreach ($lineas as $linea) {
                    $reserva->items()->create($linea);
                    $this->moverReservado($linea['producto_id'], $deposito->id, $linea['lote_id'], $linea['cantidad']);
                }

                event(new StockActualizado($reserva->empresa_id, null, array_column($lineas, 'producto_id'), [$deposito->id]));

                return $reserva->load('items');
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($referencia && ($existente = Reserva::with('items')->where('referencia_externa', $referencia)->first())) {
                return $existente;
            }

            throw $e;
        }
    }

    /**
     * Libera lo que queda reservado (todo, o las cantidades indicadas por renglón).
     *
     * @param  array|null  $cantidades  [reserva_item_id => cantidad] o null para liberar todo
     */
    public function liberar(Reserva $reserva, ?array $cantidades = null, ?string $motivo = null, string $estadoFinal = Reserva::LIBERADA): Reserva
    {
        return DB::transaction(function () use ($reserva, $cantidades, $motivo, $estadoFinal) {
            $reserva = Reserva::whereKey($reserva->id)->lockForUpdate()->firstOrFail();

            if (! $reserva->estaActiva()) {
                throw ValidationException::withMessages(['reserva' => 'La reserva ya no está activa.']);
            }

            $items = $reserva->items()->get();
            $this->bloquearProductos($items->pluck('producto_id')->all());

            foreach ($items as $item) {
                $liberar = $cantidades === null ? $item->pendiente : min($item->pendiente, max(0.0, (float) ($cantidades[$item->id] ?? 0)));

                if ($liberar <= StockService::TOLERANCIA) {
                    continue;
                }

                $this->moverReservado($item->producto_id, $reserva->deposito_id, $item->lote_id, -$liberar);
                $item->increment('cantidad_liberada', $liberar);
            }

            $this->actualizarEstado($reserva, $estadoFinal, $motivo);

            event(new StockActualizado($reserva->empresa_id, null, $items->pluck('producto_id')->all(), [$reserva->deposito_id]));

            return $reserva->fresh(['items']);
        });
    }

    /** Libera las reservas activas que pasaron su fecha de vencimiento. Devuelve cuántas liberó. */
    public function liberarVencidas(): int
    {
        $vencidas = Reserva::activas()->whereNotNull('vence_at')->where('vence_at', '<', now())->get();

        foreach ($vencidas as $reserva) {
            $this->liberar($reserva, null, 'Venció la reserva', Reserva::VENCIDA);
        }

        return $vencidas->count();
    }

    /* =========================================================
       Remitos
       ========================================================= */

    /**
     * Emite un remito de entrega y saca el stock.
     *
     * @param  array  $datos   cliente_id, deposito_id, motivo_id?, fecha, direccion_entrega, transportista,
     *                         comprobante_venta, observaciones, origen, referencia_externa
     * @param  array  $lineas  [['producto_id', 'cantidad', 'reserva_item_id'?, 'lote_id'?, 'ubicacion_id'?, 'series'?], …]
     */
    public function emitirRemito(array $datos, array $lineas, ?Reserva $reserva = null, ?User $usuario = null): Remito
    {
        $referencia = filled($datos['referencia_externa'] ?? null) ? (string) $datos['referencia_externa'] : null;

        if ($referencia && ($existente = Remito::with('items')->where('referencia_externa', $referencia)->first())) {
            return $existente;
        }

        $depositoId = $reserva ? $reserva->deposito_id : (int) ($datos['deposito_id'] ?? 0);

        try {
            return DB::transaction(function () use ($datos, $lineas, $reserva, $usuario, $depositoId, $referencia) {
                if ($reserva) {
                    $reserva = Reserva::whereKey($reserva->id)->lockForUpdate()->firstOrFail();

                    if (! $reserva->estaActiva()) {
                        throw ValidationException::withMessages(['reserva' => 'La reserva ya no está activa.']);
                    }
                }

                $lineas = array_values(array_filter($lineas, fn ($l) => abs((float) ($l['cantidad'] ?? 0)) > StockService::TOLERANCIA));

                if (! $lineas) {
                    throw ValidationException::withMessages(['lineas' => 'Indicá la cantidad que se entrega de al menos un producto.']);
                }

                // Bloquear antes de tocar lo reservado (el motor vuelve a bloquear lo mismo, ya es nuestro)
                $this->bloquearProductos(array_map(fn ($l) => (int) $l['producto_id'], $lineas));

                // 1) Lo que viene de la reserva: se libera primero, así la salida lo puede tomar
                $itemsReserva = $reserva ? $reserva->items()->with('producto')->get()->keyBy('id') : collect();
                $errores = [];

                foreach ($lineas as $n => $linea) {
                    if (empty($linea['reserva_item_id'])) {
                        continue;
                    }

                    $item = $itemsReserva->get((int) $linea['reserva_item_id']);
                    $cantidad = abs((float) $linea['cantidad']);

                    if (! $item || $item->producto_id !== (int) $linea['producto_id']) {
                        $errores[] = 'Uno de los renglones no corresponde a la reserva.';
                        continue;
                    }

                    if ($cantidad > $item->pendiente + StockService::TOLERANCIA) {
                        $errores[] = "{$item->producto?->nombre}: quedan " . Formato::cantidad($item->pendiente) . ' reservadas y se quieren entregar ' . Formato::cantidad($cantidad) . '.';
                        continue;
                    }

                    // Si la reserva era de un lote, sale ese lote
                    if ($item->lote_id) {
                        $lineas[$n]['lote_id'] = $item->lote_id;
                    }

                    $this->moverReservado($item->producto_id, $reserva->deposito_id, $item->lote_id, -$cantidad);
                    $item->increment('cantidad_entregada', $cantidad);
                }

                if ($errores) {
                    throw ValidationException::withMessages(['lineas' => array_values(array_unique($errores))]);
                }

                // 2) Remito y salida de stock
                $remito = Remito::create([
                    'numero' => Numerador::siguiente('remito'),
                    'cliente_id' => $datos['cliente_id'] ?? $reserva?->cliente_id,
                    'deposito_id' => $depositoId,
                    'reserva_id' => $reserva?->id,
                    'estado' => Remito::CONFIRMADO,
                    'origen' => $datos['origen'] ?? ($usuario ? 'manual' : 'api'),
                    'referencia_externa' => $referencia,
                    'fecha' => $datos['fecha'] ?? today(),
                    'direccion_entrega' => $datos['direccion_entrega'] ?? null,
                    'transportista' => $datos['transportista'] ?? null,
                    'comprobante_venta' => $datos['comprobante_venta'] ?? null,
                    'observaciones' => $datos['observaciones'] ?? null,
                    'user_id' => $usuario?->id,
                ]);

                $motivoId = $datos['motivo_id'] ?? Motivo::firstOrCreate(
                    ['tipo' => Movimiento::TIPO_EGRESO, 'nombre' => self::MOTIVO_ENTREGA],
                    ['activo' => true]
                )->id;

                $movimiento = $this->stock->egresar($depositoId, array_map(fn ($l) => [
                    'producto_id' => (int) $l['producto_id'],
                    'cantidad' => abs((float) $l['cantidad']),
                    'lote_id' => $l['lote_id'] ?? null,
                    'ubicacion_id' => $l['ubicacion_id'] ?? null,
                    'series' => $l['series'] ?? null,
                ], $lineas), [
                    'motivo_id' => $motivoId,
                    'remito_id' => $remito->id,
                    'origen' => $remito->origen,
                    'comprobante' => "Remito N° {$remito->numero_texto}" . (filled($datos['comprobante_venta'] ?? null) ? " · {$datos['comprobante_venta']}" : ''),
                    'observaciones' => $datos['observaciones'] ?? null,
                ], $usuario);

                $remito->update(['movimiento_id' => $movimiento->id]);

                foreach ($lineas as $linea) {
                    $remito->items()->create([
                        'reserva_item_id' => $linea['reserva_item_id'] ?? null,
                        'producto_id' => (int) $linea['producto_id'],
                        'cantidad' => abs((float) $linea['cantidad']),
                    ]);
                }

                if ($reserva) {
                    $this->actualizarEstado($reserva);
                }

                return $remito->fresh(['items']);
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($referencia && ($existente = Remito::with('items')->where('referencia_externa', $referencia)->first())) {
                return $existente;
            }

            throw $e;
        }
    }

    /** Anula un remito: la mercadería vuelve al stock y, si venía de una reserva, vuelve a quedar reservada. */
    public function anularRemito(Remito $remito, ?User $usuario = null, ?string $motivo = null): Remito
    {
        return DB::transaction(function () use ($remito, $usuario, $motivo) {
            $remito = Remito::whereKey($remito->id)->lockForUpdate()->firstOrFail();

            if ($remito->estaAnulado()) {
                throw ValidationException::withMessages(['remito' => 'El remito ya está anulado.']);
            }

            $reserva = $remito->reserva_id ? Reserva::whereKey($remito->reserva_id)->lockForUpdate()->first() : null;

            if ($remito->movimiento) {
                $this->stock->anular($remito->movimiento, $usuario, "Anulación del remito N° {$remito->numero_texto}. " . ($motivo ?? ''), [
                    'interno_remito' => true,
                ]);
            }

            // Lo que era de la reserva vuelve a reservarse (si la reserva sigue viva)
            if ($reserva && in_array($reserva->estado, [Reserva::ACTIVA, Reserva::ENTREGADA], true)) {
                foreach ($remito->items()->whereNotNull('reserva_item_id')->get() as $renglon) {
                    $item = ReservaItem::find($renglon->reserva_item_id);

                    if (! $item) {
                        continue;
                    }

                    $item->decrement('cantidad_entregada', (float) $renglon->cantidad);
                    $this->moverReservado($item->producto_id, $reserva->deposito_id, $item->lote_id, (float) $renglon->cantidad);
                }

                $reserva->update(['estado' => Reserva::ACTIVA, 'cerrada_at' => null, 'motivo_cierre' => null]);
            }

            $remito->update([
                'estado' => Remito::ANULADO,
                'anulado_at' => now(),
                'motivo_anulacion' => $motivo,
            ]);

            return $remito->fresh();
        });
    }

    /* =========================================================
       Auxiliares
       ========================================================= */

    protected function bloquearProductos(array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $productos = Producto::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $productos->load('unidad');

        return $productos;
    }

    /** Suma o resta lo reservado de un producto en un depósito (en la fila sin ubicación del lote). */
    protected function moverReservado(int $productoId, int $depositoId, ?int $loteId, float $cantidad): void
    {
        $existencia = Existencia::para($productoId, $depositoId, null, $loteId);
        $existencia->reservado = max(0.0, round((float) $existencia->reservado + $cantidad, 4));
        $existencia->save();
    }

    protected function actualizarEstado(Reserva $reserva, string $siTermina = Reserva::LIBERADA, ?string $motivo = null): void
    {
        $items = $reserva->items()->get();
        $pendiente = $items->sum(fn ($i) => $i->pendiente);

        if ($pendiente > StockService::TOLERANCIA) {
            return;
        }

        $entregado = (float) $items->sum('cantidad_entregada');
        $estado = $siTermina === Reserva::VENCIDA && $entregado <= StockService::TOLERANCIA
            ? Reserva::VENCIDA
            : ($entregado > StockService::TOLERANCIA ? Reserva::ENTREGADA : $siTermina);

        $reserva->update([
            'estado' => $estado,
            'cerrada_at' => now(),
            'motivo_cierre' => $motivo,
        ]);
    }
}
