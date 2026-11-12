<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Costo promedio ponderado (lo mantiene el motor de stock) */
        Schema::table('productos', function (Blueprint $table) {
            $table->decimal('costo_promedio', 18, 4)->default(0)->after('costo');
        });

        /* Numeración correlativa por empresa (movimientos, y más adelante remitos, órdenes de compra…) */
        Schema::create('numeradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('clave', 30);
            $table->unsignedBigInteger('ultimo')->default(0);
            $table->timestamps();

            $table->unique(['empresa_id', 'clave']);
        });

        /*
         * Existencias: cantidad actual por producto + depósito + ubicación + lote.
         * "clave" garantiza una sola fila por combinación (las columnas nulas no sirven para un unique en MySQL).
         */
        Schema::create('existencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete();
            $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();
            $table->unsignedBigInteger('lote_id')->nullable(); // la relación con lotes se agrega en el paso 9
            $table->string('clave', 80)->unique();
            $table->decimal('cantidad', 18, 4)->default(0);
            $table->decimal('reservado', 18, 4)->default(0); // se usa en el paso 11 (reservas)
            $table->timestamps();

            $table->index(['empresa_id', 'deposito_id']);
            $table->index(['producto_id', 'deposito_id']);
        });

        /* Cabecera de cada movimiento (comprobante) */
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');
            $table->string('tipo', 30);                       // inicial, ingreso, egreso, ajuste, transferencia, anulacion
            $table->string('estado', 20)->default('confirmado'); // confirmado, anulado
            $table->string('origen', 20)->default('manual');     // manual, sistema, comercial, mercado, api
            $table->string('referencia_externa', 120)->nullable(); // evita registrar dos veces lo mismo (ej. comercial:factura:1532)

            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete();
            $table->foreignId('deposito_destino_id')->nullable()->constrained('depositos')->restrictOnDelete();

            $table->string('comprobante', 100)->nullable();   // documento respaldatorio (remito, factura…)
            $table->date('fecha_comprobante')->nullable();
            $table->dateTime('fecha');                        // momento en que se registró (no se puede antedatar)
            $table->text('observaciones')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Anulación
            $table->foreignId('anula_a_id')->nullable()->constrained('movimientos')->nullOnDelete();
            $table->foreignId('anulado_por_id')->nullable()->constrained('movimientos')->nullOnDelete();
            $table->timestamp('anulado_at')->nullable();
            $table->foreignId('anulado_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_anulacion')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->unique(['empresa_id', 'referencia_externa']);
            $table->index(['empresa_id', 'fecha']);
            $table->index(['empresa_id', 'tipo']);
        });

        /* Renglones del movimiento = kardex */
        Schema::create('movimiento_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('movimiento_id')->constrained('movimientos')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete();
            $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();
            $table->unsignedBigInteger('lote_id')->nullable();
            $table->foreignId('presentacion_id')->nullable()->constrained('producto_presentaciones')->nullOnDelete();
            $table->decimal('cantidad_presentacion', 18, 4)->nullable();

            $table->decimal('cantidad', 18, 4);       // con signo: + entra, − sale
            $table->decimal('saldo', 18, 4);          // saldo del producto en el depósito después de este renglón
            $table->decimal('costo_unitario', 18, 4)->default(0);
            $table->dateTime('fecha');
            $table->timestamps();

            $table->index(['producto_id', 'deposito_id', 'id']);
            $table->index(['empresa_id', 'fecha']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimiento_items');
        Schema::dropIfExists('movimientos');
        Schema::dropIfExists('existencias');
        Schema::dropIfExists('numeradores');

        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn('costo_promedio');
        });
    }
};
