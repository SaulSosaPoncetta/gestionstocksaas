<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* ---------- Clientes ---------- */
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('razon_social');
            $table->string('nombre_fantasia')->nullable();
            $table->string('documento_tipo', 10)->default('CUIT'); // CUIT, CUIL, DNI
            $table->string('documento', 20)->nullable();
            $table->string('condicion_iva', 30)->default('consumidor_final');
            $table->string('contacto', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('localidad', 100)->nullable();
            $table->string('provincia', 100)->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'razon_social']);
            $table->index(['empresa_id', 'documento']);
        });

        /* ---------- Reservas: stock comprometido que todavía no salió ---------- */
        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete();
            $table->string('estado', 20)->default('activa'); // activa, entregada, liberada, vencida
            $table->string('origen', 20)->default('manual');  // manual, mercado, comercial, api
            $table->string('referencia_externa', 120)->nullable(); // ej. mercado:pedido:1532
            $table->dateTime('vence_at')->nullable();
            $table->text('observaciones')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cerrada_at')->nullable();
            $table->string('motivo_cierre')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'referencia_externa']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['estado', 'vence_at']);
        });

        Schema::create('reserva_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('reserva_id')->constrained('reservas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->restrictOnDelete();
            $table->decimal('cantidad', 18, 4);
            $table->decimal('cantidad_entregada', 18, 4)->default(0);
            $table->decimal('cantidad_liberada', 18, 4)->default(0);
            $table->timestamps();
        });

        /* ---------- Remitos de entrega a clientes ---------- */
        Schema::create('remitos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete();
            $table->foreignId('reserva_id')->nullable()->constrained('reservas')->nullOnDelete();
            $table->string('estado', 20)->default('confirmado'); // confirmado, anulado
            $table->string('origen', 20)->default('manual');
            $table->string('referencia_externa', 120)->nullable();
            $table->date('fecha');
            $table->string('direccion_entrega')->nullable();
            $table->string('transportista', 150)->nullable();
            $table->string('comprobante_venta', 60)->nullable(); // factura que respalda (si la hay)
            $table->text('observaciones')->nullable();
            $table->foreignId('movimiento_id')->nullable()->constrained('movimientos')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->string('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'referencia_externa']);
            $table->index(['empresa_id', 'fecha']);
        });

        Schema::create('remito_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('remito_id')->constrained('remitos')->cascadeOnDelete();
            $table->foreignId('reserva_item_id')->nullable()->constrained('reserva_items')->nullOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad', 18, 4);
            $table->timestamps();
        });

        // Los movimientos de un remito se anulan desde el remito
        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('remito_id')->nullable()->after('recepcion_compra_id')->constrained('remitos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('remito_id');
        });

        Schema::dropIfExists('remito_items');
        Schema::dropIfExists('remitos');
        Schema::dropIfExists('reserva_items');
        Schema::dropIfExists('reservas');
        Schema::dropIfExists('clientes');
    }
};
