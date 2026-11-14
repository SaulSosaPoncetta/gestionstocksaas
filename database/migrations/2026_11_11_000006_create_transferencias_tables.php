<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Depósito virtual "En tránsito" (uno por empresa, lo maneja el sistema)
        Schema::table('depositos', function (Blueprint $table) {
            $table->boolean('es_transito')->default(false)->after('es_principal');
        });

        Schema::create('transferencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');
            $table->string('estado', 20)->default('en_transito'); // en_transito, recibida, con_diferencias, anulada

            $table->foreignId('deposito_origen_id')->constrained('depositos')->restrictOnDelete();
            $table->foreignId('deposito_destino_id')->constrained('depositos')->restrictOnDelete();

            $table->string('transportista', 150)->nullable();
            $table->text('observaciones')->nullable();

            // Envío
            $table->dateTime('enviada_at');
            $table->foreignId('enviada_por_id')->nullable()->constrained('users')->nullOnDelete();

            // Recepción
            $table->dateTime('recibida_at')->nullable();
            $table->foreignId('recibida_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('destino_diferencias', 20)->nullable(); // faltante | devolver
            $table->text('observaciones_recepcion')->nullable();

            // Anulación (solo mientras está en tránsito)
            $table->dateTime('anulada_at')->nullable();
            $table->foreignId('anulada_por_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['deposito_destino_id', 'estado']);
        });

        Schema::create('transferencia_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('transferencia_id')->constrained('transferencias')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('ubicacion_origen_id')->nullable()->constrained('ubicaciones')->nullOnDelete();
            $table->foreignId('ubicacion_destino_id')->nullable()->constrained('ubicaciones')->nullOnDelete();
            $table->decimal('cantidad_enviada', 18, 4);
            $table->decimal('cantidad_recibida', 18, 4)->nullable();
            $table->timestamps();
        });

        // Cada movimiento generado por una transferencia queda vinculado (no se anula por separado)
        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('transferencia_id')->nullable()->after('motivo_id')->constrained('transferencias')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transferencia_id');
        });

        Schema::dropIfExists('transferencia_items');
        Schema::dropIfExists('transferencias');

        Schema::table('depositos', function (Blueprint $table) {
            $table->dropColumn('es_transito');
        });
    }
};
