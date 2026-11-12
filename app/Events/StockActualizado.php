<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se dispara cada vez que cambia el stock, recién cuando la transacción se confirmó.
 * Lo van a escuchar las alertas (paso 15) y los webhooks a otros sistemas (paso 17).
 */
class StockActualizado implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  int[]  $productoIds
     * @param  int[]  $depositoIds
     */
    public function __construct(
        public int $empresaId,
        public int $movimientoId,
        public array $productoIds,
        public array $depositoIds,
    ) {
    }
}
