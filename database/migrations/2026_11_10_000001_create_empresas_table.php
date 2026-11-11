<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();

            // Identificador común en todo el ecosistema MiGestión
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('panel_cliente_id')->nullable()->index(); // ID del cliente en MiGestión Panel

            // Datos fiscales (el CUIT es la clave para cruzar la empresa con otros sistemas)
            $table->string('razon_social');
            $table->string('nombre_fantasia')->nullable();
            $table->string('cuit', 13)->unique();
            $table->enum('condicion_iva', [
                'responsable_inscripto',
                'monotributo',
                'exento',
                'consumidor_final',
            ])->default('responsable_inscripto');

            // Contacto
            $table->string('domicilio')->nullable();
            $table->string('localidad')->nullable();
            $table->string('provincia')->nullable();
            $table->string('telefono', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('logo')->nullable();

            // Configuración del módulo de stock
            $table->enum('metodo_costeo', ['promedio_ponderado', 'peps'])->default('promedio_ponderado');
            $table->boolean('permite_stock_negativo')->default(false);
            $table->boolean('usa_lotes')->default(false);
            $table->boolean('usa_series')->default(false);
            $table->unsignedTinyInteger('decimales_cantidad')->default(2);

            // Suscripción (la controla MiGestión Panel)
            $table->boolean('activa')->default(true);
            $table->date('vence_el')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
