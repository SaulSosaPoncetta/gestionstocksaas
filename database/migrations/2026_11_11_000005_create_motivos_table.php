<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Motivos de ingresos, egresos y ajustes (configurables por empresa) */
        Schema::create('motivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('tipo', 20);                   // ingreso, egreso, ajuste
            $table->string('nombre', 80);
            $table->boolean('pide_costo')->default(false);   // ingresos: el costo cargado recalcula el promedio
            $table->boolean('requiere_observacion')->default(false);
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'tipo', 'nombre']);
        });

        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('motivo_id')->nullable()->after('tipo')->constrained('motivos')->restrictOnDelete();
        });

        // En los ajustes por conteo se guarda lo que se contó (la cantidad es la diferencia)
        Schema::table('movimiento_items', function (Blueprint $table) {
            $table->decimal('cantidad_contada', 18, 4)->nullable()->after('cantidad');
        });
    }

    public function down(): void
    {
        Schema::table('movimiento_items', function (Blueprint $table) {
            $table->dropColumn('cantidad_contada');
        });

        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('motivo_id');
        });

        Schema::dropIfExists('motivos');
    }
};
