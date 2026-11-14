<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Tareas programadas
|--------------------------------------------------------------------------
| En el VPS tiene que estar el cron de Laravel:
| * * * * * cd /var/www/gestionstock && php artisan schedule:run >> /dev/null 2>&1
*/

// Libera el stock de las reservas que vencieron
Schedule::command('stock:liberar-reservas')->everyFifteenMinutes()->withoutOverlapping();
