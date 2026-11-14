<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* ---------- Toma de inventario físico (conteo) ---------- */
        Schema::create('tomas_inventario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->unsignedBigInteger('numero');
            $table->foreignId('deposito_id')->constrained('depositos')->restrictOnDelete();
            // Alcance: todo el depósito, o una ubicación (con sus sububicaciones) y/o una categoría (con sus subcategorías)
            $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->nullOnDelete();
            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->string('descripcion')->nullable();
            $table->string('estado', 20)->default('en_curso'); // en_curso, cerrada, cancelada
            $table->boolean('congela')->default(true);           // bloquea los movimientos del depósito mientras se cuenta
            $table->boolean('a_ciegas')->default(false);         // el operador no ve lo que dice el sistema
            $table->boolean('incluir_sin_stock')->default(false); // lista también productos del alcance sin existencias
            $table->text('observaciones')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Cierre
            $table->string('no_contados', 10)->nullable(); // ignorar | cero
            $table->foreignId('movimiento_id')->nullable()->constrained('movimientos')->nullOnDelete();
            $table->decimal('valor_faltante', 16, 2)->default(0);
            $table->decimal('valor_sobrante', 16, 2)->default(0);
            $table->unsignedInteger('lineas_con_diferencia')->default(0);
            $table->timestamp('cerrada_at')->nullable();
            $table->foreignId('cerrada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelacion')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'numero']);
            $table->index(['empresa_id', 'estado']);
            $table->index(['deposito_id', 'estado']);
        });

        Schema::create('toma_inventario_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('toma_inventario_id')->constrained('tomas_inventario')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->restrictOnDelete();
            $table->foreignId('ubicacion_id')->nullable()->constrained('ubicaciones')->nullOnDelete();
            $table->foreignId('lote_id')->nullable()->constrained('lotes')->nullOnDelete();
            // Lote que no existía y apareció en el conteo: se crea recién al cerrar (lo crea el motor de stock)
            $table->string('lote_codigo', 60)->nullable();
            $table->date('lote_vencimiento')->nullable();
            $table->string('clave', 120); // producto-ubicación-lote, para no repetir renglones

            $table->decimal('teorico', 14, 4)->default(0);       // lo que dice el sistema (se actualiza al contar)
            $table->decimal('contado', 14, 4)->nullable();        // null = todavía no se contó
            $table->json('series_teoricas')->nullable();
            $table->json('series_contadas')->nullable();
            $table->decimal('costo_unitario', 14, 4)->default(0); // para valorizar la diferencia
            $table->decimal('ajuste', 14, 4)->nullable();         // diferencia aplicada al cerrar
            $table->boolean('agregado')->default(false);          // lo agregó quien contaba (no estaba en la lista)
            $table->foreignId('contado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contado_at')->nullable();
            $table->timestamps();

            $table->unique(['toma_inventario_id', 'clave']);
            $table->index(['toma_inventario_id', 'producto_id']);
        });

        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('toma_inventario_id')->nullable()->after('remito_id')->constrained('tomas_inventario')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('toma_inventario_id');
        });

        Schema::dropIfExists('toma_inventario_items');
        Schema::dropIfExists('tomas_inventario');
    }
};
