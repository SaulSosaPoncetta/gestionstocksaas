<?php

namespace App\Services;

use App\Models\Deposito;
use App\Models\Motivo;
use App\Models\Movimiento;
use App\Models\Numerador;
use App\Models\Serie;
use App\Models\Transferencia;
use App\Models\Ubicacion;
use App\Models\User;
use App\Support\Formato;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transferencias entre depósitos en dos pasos:
 *   1) Envío:     origen → "En tránsito" (el stock sale del origen y queda viajando).
 *   2) Recepción: "En tránsito" → destino, con lo que realmente llegó.
 *      Lo que no llegó se registra como faltante (egreso) o vuelve al origen.
 * Viajan los mismos lotes y series que salieron: cada renglón de la transferencia es un producto + lote.
 * Mientras está en tránsito se puede anular: la mercadería vuelve al origen.
 */
class TransferenciaService
{
    public const MOTIVO_FALTANTE = 'Faltante en transferencia';

    public function __construct(protected StockService $stock)
    {
    }

    /**
     * @param  array  $lineas  [['producto_id' => 1, 'cantidad' => 5, 'ubicacion_id' => null, 'lote_id' => null, 'series' => [...]], …]
     * @param  bool   $recibirAhora  envío y recepción completa en el mismo momento
     */
    public function enviar(int $origenId, int $destinoId, array $lineas, array $datos = [], ?User $usuario = null, bool $recibirAhora = false): Transferencia
    {
        $origen = Deposito::activos()->find($origenId);
        $destino = Deposito::activos()->find($destinoId);

        if (! $origen || ! $destino) {
            throw ValidationException::withMessages(['deposito_destino_id' => 'Los depósitos de origen y destino tienen que existir y estar activos.']);
        }

        if ($origen->id === $destino->id) {
            throw ValidationException::withMessages(['deposito_destino_id' => 'El depósito de destino tiene que ser distinto al de origen.']);
        }

        if ($usuario && ! $usuario->puedeOperarDeposito($origen->id)) {
            throw ValidationException::withMessages(['deposito_origen_id' => "No tenés permiso para enviar desde {$origen->nombre}."]);
        }

        // Una línea por producto + ubicación + lote
        $agrupadas = [];
        foreach ($lineas as $linea) {
            $clave = implode('-', [(int) ($linea['producto_id'] ?? 0), (int) ($linea['ubicacion_id'] ?? 0), (int) ($linea['lote_id'] ?? 0)]);
            $agrupadas[$clave] ??= [
                'producto_id' => (int) ($linea['producto_id'] ?? 0),
                'ubicacion_id' => ! empty($linea['ubicacion_id']) ? (int) $linea['ubicacion_id'] : null,
                'lote_id' => ! empty($linea['lote_id']) ? (int) $linea['lote_id'] : null,
                'cantidad' => 0,
                'series' => null,
            ];
            $agrupadas[$clave]['cantidad'] += abs((float) ($linea['cantidad'] ?? 0));

            $series = Serie::parsear($linea['series'] ?? []);
            if ($series) {
                $agrupadas[$clave]['series'] = array_values(array_unique(array_merge($agrupadas[$clave]['series'] ?? [], $series)));
            }
        }
        $lineas = array_values($agrupadas);

        return DB::transaction(function () use ($origen, $destino, $lineas, $datos, $usuario, $recibirAhora) {
            $transito = Deposito::transito();

            $transferencia = Transferencia::create([
                'numero' => Numerador::siguiente('transferencia'),
                'estado' => Transferencia::EN_TRANSITO,
                'deposito_origen_id' => $origen->id,
                'deposito_destino_id' => $destino->id,
                'transportista' => $datos['transportista'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'enviada_at' => now(),
                'enviada_por_id' => $usuario?->id,
            ]);

            // Salida del origen hacia "En tránsito" (entra en tránsito exactamente lo que salió)
            $envio = $this->stock->registrar(
                Movimiento::TIPO_TRANSFERENCIA,
                $origen->id,
                $this->stock->renglonesEspejo($lineas, $origen->id, $transito->id),
                [
                    'deposito_destino_id' => $transito->id,
                    'transferencia_id' => $transferencia->id,
                    'interno_transferencia' => true,
                    'comprobante' => "Transferencia N° {$transferencia->numero_texto}",
                    'observaciones' => "Envío a {$destino->nombre}" . (filled($datos['observaciones'] ?? null) ? ". {$datos['observaciones']}" : ''),
                ],
                $usuario
            );

            // Renglones de la transferencia: uno por cada parte que llegó a tránsito (producto + lote + series)
            $items = $envio->items()->with('series')->orderBy('id')->get();
            $salidas = $items->where('deposito_id', $origen->id)->values();
            $entradas = $items->where('deposito_id', $transito->id)->values();

            foreach ($entradas as $n => $entrada) {
                $transferencia->items()->create([
                    'producto_id' => $entrada->producto_id,
                    'lote_id' => $entrada->lote_id,
                    'ubicacion_origen_id' => $salidas->get($n)?->ubicacion_id,
                    'cantidad_enviada' => (float) $entrada->cantidad,
                    'series' => $entrada->series->isNotEmpty() ? $entrada->series->pluck('numero')->values()->all() : null,
                ]);
            }

            if ($recibirAhora) {
                $recepcion = $transferencia->items()->get()->mapWithKeys(fn ($item) => [
                    $item->id => [
                        'cantidad_recibida' => (float) $item->cantidad_enviada,
                        'series_recibidas' => $item->series,
                    ],
                ])->all();

                $this->recibir($transferencia, $recepcion, [], $usuario);
            }

            return $transferencia->fresh(['items']);
        });
    }

    /**
     * @param  array  $recepcion  [item_id => ['cantidad_recibida' => 4, 'ubicacion_destino_id' => null, 'series_recibidas' => [...]], …]
     * @param  array  $datos      destino_diferencias (faltante|devolver), observaciones_recepcion
     */
    public function recibir(Transferencia $transferencia, array $recepcion, array $datos = [], ?User $usuario = null): Transferencia
    {
        return DB::transaction(function () use ($transferencia, $recepcion, $datos, $usuario) {
            $transferencia = Transferencia::whereKey($transferencia->id)->lockForUpdate()->firstOrFail();

            if (! $transferencia->enTransito()) {
                throw ValidationException::withMessages(['transferencia' => 'Esta transferencia ya fue recibida o anulada.']);
            }

            if ($usuario && ! $usuario->puedeOperarDeposito($transferencia->deposito_destino_id)) {
                throw ValidationException::withMessages(['transferencia' => 'No tenés permiso para recibir en el depósito de destino.']);
            }

            $transito = Deposito::transito();
            $destino = Deposito::withTrashed()->findOrFail($transferencia->deposito_destino_id);
            $items = $transferencia->items()->with('producto')->get();
            $ubicacionesValidas = Ubicacion::where('deposito_id', $destino->id)->activas()->pluck('id')->all();

            $errores = [];
            $llegaron = [];   // [item, cantidad, series, ubicacion]
            $faltaron = [];   // [item, cantidad, series]

            foreach ($items as $item) {
                $fila = $recepcion[$item->id] ?? [];
                $nombre = $item->producto?->nombre;
                $enviada = (float) $item->cantidad_enviada;
                $seriesEnviadas = $item->series ?? [];

                if ($seriesEnviadas) {
                    // Con series: llegó lo que se marcó
                    $seriesRecibidas = array_values(array_intersect($seriesEnviadas, Serie::parsear($fila['series_recibidas'] ?? [])));
                    $recibida = (float) count($seriesRecibidas);
                    $seriesFaltantes = array_values(array_diff($seriesEnviadas, $seriesRecibidas));
                } else {
                    $recibida = isset($fila['cantidad_recibida']) && $fila['cantidad_recibida'] !== '' ? (float) $fila['cantidad_recibida'] : null;
                    $seriesRecibidas = $seriesFaltantes = null;

                    if ($recibida === null || $recibida < 0) {
                        $errores[] = "{$nombre}: indicá cuánto llegó (0 si no llegó nada).";
                        continue;
                    }

                    if ($recibida > $enviada + StockService::TOLERANCIA) {
                        $errores[] = "{$nombre}: no puede llegar más de lo enviado (" . Formato::cantidad($enviada) . '). Si sobró mercadería, registrá un ingreso aparte.';
                        continue;
                    }
                }

                $ubicacion = ! empty($fila['ubicacion_destino_id']) ? (int) $fila['ubicacion_destino_id'] : null;
                if ($ubicacion && ! in_array($ubicacion, $ubicacionesValidas, true)) {
                    $errores[] = "{$nombre}: la ubicación elegida no pertenece a {$destino->nombre}.";
                    continue;
                }

                $item->cantidad_recibida = $recibida;
                $item->ubicacion_destino_id = $ubicacion;
                $item->series_recibidas = $seriesRecibidas;

                if ($recibida > StockService::TOLERANCIA) {
                    $llegaron[] = ['item' => $item, 'cantidad' => $recibida, 'series' => $seriesRecibidas, 'ubicacion' => $ubicacion];
                }

                $diferencia = round($enviada - $recibida, 4);
                if ($diferencia > StockService::TOLERANCIA) {
                    $faltaron[] = ['item' => $item, 'cantidad' => $diferencia, 'series' => $seriesFaltantes];
                }
            }

            if ($errores) {
                throw ValidationException::withMessages(['recepcion' => $errores]);
            }

            $destinoDiferencias = $faltaron ? ($datos['destino_diferencias'] ?? 'faltante') : null;

            if ($destinoDiferencias && ! array_key_exists($destinoDiferencias, Transferencia::DIFERENCIAS)) {
                throw ValidationException::withMessages(['destino_diferencias' => 'Indicá qué hacer con lo que no llegó.']);
            }

            $base = [
                'transferencia_id' => $transferencia->id,
                'interno_transferencia' => true,
                'comprobante' => "Transferencia N° {$transferencia->numero_texto}",
            ];

            // Sale de tránsito exactamente el lote (y las series) de ese renglón
            $salidaTransito = fn (array $r) => [
                'producto_id' => $r['item']->producto_id,
                'deposito_id' => $transito->id,
                'lote_id' => $r['item']->lote_id,
                'series' => $r['series'] ?: null,
                'cantidad' => -$r['cantidad'],
                'exacta' => true,
            ];

            // 1) Lo que llegó: "En tránsito" → destino
            if ($llegaron) {
                $renglones = [];
                foreach ($llegaron as $r) {
                    $renglones[] = $salidaTransito($r);
                    $renglones[] = ['producto_id' => $r['item']->producto_id, 'deposito_id' => $destino->id, 'ubicacion_id' => $r['ubicacion'], 'cantidad' => $r['cantidad'], 'espejo_de' => count($renglones) - 1];
                }

                $this->stock->registrar(Movimiento::TIPO_TRANSFERENCIA, $transito->id, $renglones, $base + [
                    'deposito_destino_id' => $destino->id,
                    'observaciones' => "Recepción en {$destino->nombre}",
                ], $usuario);
            }

            // 2) Lo que no llegó
            if ($faltaron && $destinoDiferencias === 'faltante') {
                $motivo = Motivo::firstOrCreate(
                    ['tipo' => Movimiento::TIPO_EGRESO, 'nombre' => self::MOTIVO_FALTANTE],
                    ['activo' => true, 'orden' => 90]
                );

                $this->stock->registrar(Movimiento::TIPO_EGRESO, $transito->id, array_map($salidaTransito, $faltaron), $base + [
                    'motivo_id' => $motivo->id,
                    'observaciones' => 'Faltante en la recepción' . (filled($datos['observaciones_recepcion'] ?? null) ? ": {$datos['observaciones_recepcion']}" : ''),
                ], $usuario);
            }

            if ($faltaron && $destinoDiferencias === 'devolver') {
                $renglones = [];
                foreach ($faltaron as $r) {
                    $renglones[] = $salidaTransito($r);
                    $renglones[] = ['producto_id' => $r['item']->producto_id, 'deposito_id' => $transferencia->deposito_origen_id, 'cantidad' => $r['cantidad'], 'espejo_de' => count($renglones) - 1];
                }

                $this->stock->registrar(Movimiento::TIPO_TRANSFERENCIA, $transito->id, $renglones, $base + [
                    'deposito_destino_id' => $transferencia->deposito_origen_id,
                    'observaciones' => 'Devolución al origen de lo que no llegó',
                ], $usuario);
            }

            foreach ($items as $item) {
                $item->save();
            }

            $transferencia->update([
                'estado' => $faltaron ? Transferencia::CON_DIFERENCIAS : Transferencia::RECIBIDA,
                'recibida_at' => now(),
                'recibida_por_id' => $usuario?->id,
                'destino_diferencias' => $destinoDiferencias,
                'observaciones_recepcion' => $datos['observaciones_recepcion'] ?? null,
            ]);

            return $transferencia->fresh(['items']);
        });
    }

    /** Anula una transferencia en tránsito: la mercadería vuelve al origen. */
    public function anular(Transferencia $transferencia, ?User $usuario = null, ?string $motivo = null): Transferencia
    {
        return DB::transaction(function () use ($transferencia, $usuario, $motivo) {
            $transferencia = Transferencia::whereKey($transferencia->id)->lockForUpdate()->firstOrFail();

            if (! $transferencia->enTransito()) {
                throw ValidationException::withMessages(['transferencia' => 'Solo se puede anular una transferencia que todavía está en tránsito.']);
            }

            $envio = $transferencia->movimientos()
                ->where('tipo', Movimiento::TIPO_TRANSFERENCIA)
                ->where('estado', Movimiento::ESTADO_CONFIRMADO)
                ->orderBy('id')
                ->firstOrFail();

            $this->stock->anular($envio, $usuario, "Anulación de la transferencia N° {$transferencia->numero_texto}. " . ($motivo ?? ''), [
                'interno_transferencia' => true,
            ]);

            $transferencia->update([
                'estado' => Transferencia::ANULADA,
                'anulada_at' => now(),
                'anulada_por_id' => $usuario?->id,
                'motivo_anulacion' => $motivo,
            ]);

            return $transferencia->fresh();
        });
    }
}
