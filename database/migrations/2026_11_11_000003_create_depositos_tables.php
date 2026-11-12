<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* ---------- Depósitos / sucursales ---------- */
        Schema::create('depositos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('codigo', 20);
            $table->string('nombre', 100);
            $table->enum('tipo', ['deposito', 'sucursal', 'consignacion', 'virtual'])->default('deposito');

            $table->string('direccion')->nullable();
            $table->string('localidad', 100)->nullable();
            $table->string('provincia', 100)->nullable();
            $table->string('telefono', 50)->nullable();
            $table->foreignId('responsable_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('es_principal')->default(false);
            $table->boolean('usa_ubicaciones')->default(false);
            $table->boolean('permite_stock_negativo')->nullable(); // null = usa la configuración de la empresa
            $table->boolean('activo')->default(true);
            $table->text('observaciones')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'codigo']);
        });

        /* ---------- Ubicaciones dentro del depósito (zona › estante › posición) ---------- */
        Schema::create('ubicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('deposito_id')->constrained('depositos')->cascadeOnDelete();
            $table->foreignId('padre_id')->nullable()->constrained('ubicaciones')->restrictOnDelete();
            $table->enum('tipo', ['zona', 'pasillo', 'estante', 'posicion'])->default('posicion');
            $table->string('codigo', 40);   // código completo, ej. A-01-3
            $table->string('nombre', 100)->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['deposito_id', 'codigo']);
            $table->index(['deposito_id', 'padre_id']);
        });

        /* ---------- Depósitos que puede operar cada usuario ---------- */
        Schema::create('deposito_usuario', function (Blueprint $table) {
            $table->foreignId('deposito_id')->constrained('depositos')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['deposito_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // true = opera todos los depósitos; false = solo los asignados en deposito_usuario
            $table->boolean('todos_los_depositos')->default(true)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('todos_los_depositos');
        });

        Schema::dropIfExists('deposito_usuario');
        Schema::dropIfExists('ubicaciones');
        Schema::dropIfExists('depositos');
    }
};
