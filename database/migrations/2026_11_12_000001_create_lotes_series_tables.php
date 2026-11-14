<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* ---------- Lotes ---------- */
        Schema::create('lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('codigo', 60);
            $table->date('fecha_elaboracion')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('observaciones')->nullable();
            $table->timestamps();

            $table->unique(['producto_id', 'codigo']);
            $table->index(['empresa_id', 'fecha_vencimiento']);
        });

        Schema::table('existencias', function (Blueprint $table) {
            $table->foreign('lote_id')->references('id')->on('lotes')->restrictOnDelete();
        });

        Schema::table('movimiento_items', function (Blueprint $table) {
            $table->foreign('lote_id')->references('id')->on('lotes')->restrictOnDelete();
        });

        /* ---------- Números de serie ---------- */
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('numero', 100);
            $table->string('estado', 20)->default('en_stock'); // en_stock | egresado
            $table->foreignId('deposito_id')->nullable()->constrained('depositos')->nullOnDelete();
            $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            $table->timestamps();

            $table->unique(['producto_id', 'numero']);
            $table->index(['empresa_id', 'numero']);
            $table->index(['producto_id', 'deposito_id', 'estado']);
        });

        // Trazabilidad: qué series se movieron en cada renglón del kardex
        Schema::create('movimiento_item_serie', function (Blueprint $table) {
            $table->foreignId('movimiento_item_id')->constrained('movimiento_items')->cascadeOnDelete();
            $table->foreignId('serie_id')->constrained('series')->cascadeOnDelete();

            $table->primary(['movimiento_item_id', 'serie_id']);
            $table->index('serie_id');
        });

        /* ---------- Transferencias: un renglón por lote, con sus series ---------- */
        Schema::table('transferencia_items', function (Blueprint $table) {
            $table->foreignId('lote_id')->nullable()->after('producto_id')->constrained('lotes')->restrictOnDelete();
            $table->json('series')->nullable()->after('cantidad_recibida');          // números enviados
            $table->json('series_recibidas')->nullable()->after('series');           // números que llegaron
        });

        /* ---------- Aviso de vencimientos ---------- */
        Schema::table('empresas', function (Blueprint $table) {
            $table->unsignedSmallInteger('dias_aviso_vencimiento')->default(30)->after('usa_series');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('dias_aviso_vencimiento');
        });

        Schema::table('transferencia_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lote_id');
            $table->dropColumn(['series', 'series_recibidas']);
        });

        Schema::dropIfExists('movimiento_item_serie');
        Schema::dropIfExists('series');

        Schema::table('movimiento_items', function (Blueprint $table) {
            $table->dropForeign(['lote_id']);
        });

        Schema::table('existencias', function (Blueprint $table) {
            $table->dropForeign(['lote_id']);
        });

        Schema::dropIfExists('lotes');
    }
};
