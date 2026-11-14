<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Services\SalidaService;
use App\Support\EmpresaActual;
use Illuminate\Console\Command;

/**
 * Libera las reservas que pasaron su fecha de vencimiento, empresa por empresa.
 * Corre solo cada 15 minutos (routes/console.php). A mano: php artisan stock:liberar-reservas
 */
class LiberarReservasVencidas extends Command
{
    protected $signature = 'stock:liberar-reservas';

    protected $description = 'Libera el stock de las reservas vencidas';

    public function handle(SalidaService $salidas): int
    {
        $total = 0;

        foreach (Empresa::activas()->pluck('id') as $empresaId) {
            $total += EmpresaActual::usar($empresaId, fn () => $salidas->liberarVencidas());
        }

        $this->info($total ? "Se liberaron {$total} reservas vencidas." : 'No había reservas vencidas.');

        return self::SUCCESS;
    }
}
