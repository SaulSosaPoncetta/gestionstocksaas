<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Productos
         * - simple: producto común, lleva stock.
         * - con_variantes: agrupador (ej. "Remera básica"), NO lleva stock; sus variantes sí.
         * - variante: hija de un con_variantes (ej. "Remera básica M / Negro"), lleva stock.
         * - servicio: no lleva stock.
         */
        Schema::create('productos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('padre_id')->nullable()->constrained('productos')->cascadeOnDelete();
            $table->enum('tipo', ['simple', 'con_variantes', 'variante', 'servicio'])->default('simple');

            $table->string('sku', 50);
            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();

            $table->foreignId('categoria_id')->nullable()->constrained('categorias')->nullOnDelete();
            $table->foreignId('marca_id')->nullable()->constrained('marcas')->nullOnDelete();
            $table->foreignId('unidad_medida_id')->constrained('unidades_medida')->restrictOnDelete();
            $table->foreignId('alicuota_iva_id')->constrained('alicuotas_iva')->restrictOnDelete();

            $table->json('opciones_variantes')->nullable(); // en el agrupador: {"Talle":["S","M"],"Color":["Negro"]}
            $table->json('atributos')->nullable();          // en la variante: {"Talle":"M","Color":"Negro"}

            $table->boolean('controla_stock')->default(true);
            $table->boolean('usa_lotes')->default(false);
            $table->boolean('usa_series')->default(false);

            // Precios sin IVA
            $table->decimal('costo', 18, 4)->default(0);
            $table->decimal('margen', 8, 2)->nullable();
            $table->decimal('precio_venta', 18, 4)->default(0);

            // Niveles de stock (en la unidad del producto)
            $table->decimal('stock_minimo', 18, 4)->default(0);
            $table->decimal('punto_reposicion', 18, 4)->nullable();
            $table->decimal('stock_maximo', 18, 4)->nullable();

            $table->decimal('peso_kg', 10, 3)->nullable();
            $table->boolean('activo')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['empresa_id', 'sku']);
            $table->index(['empresa_id', 'nombre']);
            $table->index(['empresa_id', 'categoria_id']);
            $table->index(['empresa_id', 'padre_id']);
        });

        /* Presentaciones de venta/compra: "Caja x 12", "Pack x 6", "Bolsa 25 kg" */
        Schema::create('producto_presentaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('nombre', 60);
            $table->decimal('cantidad', 18, 4);              // cuántas unidades del producto contiene
            $table->decimal('precio_venta', 18, 4)->nullable(); // precio propio sin IVA (opcional)
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        /* Códigos de barras (varios por producto; pueden ser de una presentación) */
        Schema::create('producto_codigos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->foreignId('presentacion_id')->nullable()->constrained('producto_presentaciones')->cascadeOnDelete();
            $table->string('codigo', 64);
            $table->enum('tipo', ['ean13', 'ean8', 'upc', 'interno', 'proveedor', 'otro'])->default('ean13');
            $table->timestamps();

            $table->unique(['empresa_id', 'codigo']);
        });

        /* Imágenes */
        Schema::create('producto_imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('productos')->cascadeOnDelete();
            $table->string('ruta');
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producto_imagenes');
        Schema::dropIfExists('producto_codigos');
        Schema::dropIfExists('producto_presentaciones');
        Schema::dropIfExists('productos');
    }
};
