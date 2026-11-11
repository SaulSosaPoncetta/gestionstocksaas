<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Paginación con estilos de Bootstrap 5
        Paginator::useBootstrapFive();

        // En desarrollo, avisa si se olvida un with() y se disparan consultas de más
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
