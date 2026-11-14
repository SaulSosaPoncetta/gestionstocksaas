<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Costo promedio del producto después de cada renglón del kardex (para valorizar a una fecha pasada)
        Schema::table('movimiento_items', function (Blueprint $table) {
            $table->decimal('costo_promedio', 18, 4)->nullable()->after('costo_unitario');
            $table->index(['producto_id', 'fecha']);
        });

        // Renglones anteriores: la mejor aproximación disponible es el costo con el que se registraron
        DB::table('movimiento_items')->whereNull('costo_promedio')->update(['costo_promedio' => DB::raw('costo_unitario')]);
    }

    public function down(): void
    {
        Schema::table('movimiento_items', function (Blueprint $table) {
            $table->dropIndex(['producto_id', 'fecha']);
            $table->dropColumn('costo_promedio');
        });
    }
};
