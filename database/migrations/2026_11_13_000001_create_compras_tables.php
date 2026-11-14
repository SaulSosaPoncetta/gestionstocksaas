<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Módulos contratados por la empresa (null = todos). Lo maneja MiGestión según el plan. */
        Schema::table('empresas', function (Blueprint $table) {
            $table->json('modulos')->nullable()->after('dias_aviso_vencimiento');
        });

        /* ---------- Proveedores ---------- */
        Schema::create('proveedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('razon_social');
            $table->string('nombre_fantasia')->nullable();
            $table->string('cuit', 13)->nullable();
            $table->string('condicion_iva', 30)->default('responsable_inscripto');
            $table->string('contacto', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('domicilio')->nullable();
            $table->string('localidad', 100)->nullable();
            $table->string('provincia', 100)->nullable();
            $table->string('condiciones_pago', 150)->nullable();
            $table->unsignedSmallInteger('plazo_entrega_dias')->nullable();
            $table->text('observaciones')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'cuit']);
            $table->index(['empresa_id', 'razon_social']);
        });

        /* ---------- Qué le compramos a cada proveedor ---------- */
        Schema::create('producto_proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->cascadeOnDelete();
            $table->string('codigo_proveedor', 60)->nullable();   // cómo lo llama el proveedor
            $table->foreignId('presentacion_id')->nullable()->constrained('producto_presentaciones')->nullOnDelete(); // unidad de compra (ej. caja x 12)
            $table->decimal('costo', 18, 4)->nullable();           // último costo por unidad del producto, sin IVA
            $table->decimal('minimo_compra', 18, 4)->nullable();   // en unidades del producto
            $table->unsignedSmallInteger('plazo_entrega_dias')->nullable();
            $table->boolean('es_principal')->default(false);
            $table->timestamp('ultima_compra_at')->nullable();
            $table->timestamps();

            $table->unique(['producto_id', 'proveedor_id']);
        });

        /* ---------- Órdenes de compra ---------- */
        Schema::create('ordenes_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete(); // dónde se recibe
            $table->string('estado', 20)->default('borrador'); // borrador, enviada, parcial, recibida, cerrada, cancelada
            $table->date('fecha');
            $table->date('fecha_entrega')->nullable();
            $table->string('condiciones_pago', 150)->nullable();
            $table->text('observaciones')->nullable();

            $table->decimal('subtotal', 18, 2)->default(0); // sin IVA
            $table->decimal('iva', 18, 2)->default(0);
            $table->decimal('total', 18, 2)->default(0);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enviada_at')->nullable();
            $table->timestamp('cerrada_at')->nullable();
            $table->string('motivo_cierre')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['proveedor_id', 'estado']);
        });

        Schema::create('orden_compra_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('presentacion_id')->nullable()->constrained('producto_presentaciones')->nullOnDelete();
            $table->decimal('cantidad_presentacion', 18, 4)->nullable();
            $table->decimal('cantidad', 18, 4);                       // en unidades del producto
            $table->decimal('cantidad_recibida', 18, 4)->default(0);
            $table->decimal('costo_unitario', 18, 4)->default(0);     // por unidad del producto, sin IVA
            $table->decimal('alicuota_iva', 5, 2)->default(0);
            $table->timestamps();
        });

        /* ---------- Recepciones (cada entrega del proveedor) ---------- */
        Schema::create('recepciones_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');
            $table->foreignId('orden_compra_id')->constrained('ordenes_compra')->restrictOnDelete();
            $table->foreignId('proveedor_id')->constrained('proveedores')->restrictOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete();
            $table->string('remito', 60)->nullable();
            $table->string('factura', 60)->nullable();
            $table->date('fecha');
            $table->string('estado', 20)->default('confirmada'); // confirmada, anulada
            $table->foreignId('movimiento_id')->nullable()->constrained('movimientos')->nullOnDelete();
            $table->text('observaciones')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulada_at')->nullable();
            $table->string('motivo_anulacion')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
        });

        Schema::create('recepcion_compra_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('recepcion_compra_id')->constrained('recepciones_compra')->cascadeOnDelete();
            $table->foreignId('orden_compra_item_id')->constrained('orden_compra_items')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->decimal('cantidad', 18, 4);
            $table->decimal('costo_unitario', 18, 4)->default(0);
            $table->timestamps();
        });

        // Los movimientos de una recepción se anulan desde Compras
        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('recepcion_compra_id')->nullable()->after('transferencia_id')->constrained('recepciones_compra')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recepcion_compra_id');
        });

        Schema::dropIfExists('recepcion_compra_items');
        Schema::dropIfExists('recepciones_compra');
        Schema::dropIfExists('orden_compra_items');
        Schema::dropIfExists('ordenes_compra');
        Schema::dropIfExists('producto_proveedor');
        Schema::dropIfExists('proveedores');

        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('modulos');
        });
    }
};
