<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* ---------- Alícuotas de IVA (globales, códigos ARCA/AFIP) ---------- */
        Schema::create('alicuotas_iva', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('codigo_afip')->unique();
            $table->string('nombre', 30);
            $table->decimal('porcentaje', 5, 2);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        $ahora = now();
        DB::table('alicuotas_iva')->insert([
            ['codigo_afip' => 5, 'nombre' => '21%', 'porcentaje' => 21.00, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo_afip' => 4, 'nombre' => '10,5%', 'porcentaje' => 10.50, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo_afip' => 6, 'nombre' => '27%', 'porcentaje' => 27.00, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo_afip' => 8, 'nombre' => '5%', 'porcentaje' => 5.00, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo_afip' => 9, 'nombre' => '2,5%', 'porcentaje' => 2.50, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo_afip' => 3, 'nombre' => '0%', 'porcentaje' => 0.00, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo_afip' => 2, 'nombre' => 'Exento', 'porcentaje' => 0.00, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['codigo_afip' => 1, 'nombre' => 'No gravado', 'porcentaje' => 0.00, 'activa' => true, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        /* ---------- Categorías (árbol ilimitado) ---------- */
        Schema::create('categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('padre_id')->nullable()->constrained('categorias')->restrictOnDelete();
            $table->string('nombre', 100);
            $table->string('codigo', 30)->nullable();
            $table->string('descripcion', 500)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'padre_id']);
        });

        /* ---------- Marcas ---------- */
        Schema::create('marcas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 100);
            $table->boolean('activa')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['empresa_id', 'nombre']);
        });

        /* ---------- Unidades de medida ---------- */
        // Una unidad puede depender de una unidad base del mismo tipo (gramo → kilogramo x 0,001; docena → unidad x 12).
        // Las presentaciones propias de cada producto (caja x 12, pack x 6) se cargan en el paso 4.
        Schema::create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->string('nombre', 50);
            $table->string('abreviatura', 10);
            $table->enum('tipo', ['unidad', 'peso', 'volumen', 'longitud', 'superficie', 'otro'])->default('unidad');
            $table->foreignId('unidad_base_id')->nullable()->constrained('unidades_medida')->restrictOnDelete();
            $table->decimal('factor', 18, 6)->default(1); // cuántas unidades base equivale 1 de esta
            $table->boolean('permite_decimales')->default(false);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['empresa_id', 'abreviatura']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unidades_medida');
        Schema::dropIfExists('marcas');
        Schema::dropIfExists('categorias');
        Schema::dropIfExists('alicuotas_iva');
    }
};
