<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sistemas del ecosistema que cada empresa tiene conectados
        Schema::create('integraciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();

            $table->string('sistema', 30);          // comercial, mercado, contable, panel (ver config/ecosistema.php)
            $table->string('url_base')->nullable(); // URL de la API del otro sistema
            $table->string('id_empresa_remota')->nullable(); // ID de esta empresa en el otro sistema

            // Seguridad
            $table->text('token_saliente')->nullable();        // token para llamar al otro sistema (encriptado)
            $table->string('token_entrante_hash', 64)->nullable()->unique(); // hash del token con que el otro sistema nos llama
            $table->text('webhook_secreto')->nullable();       // clave HMAC para firmar/verificar webhooks (encriptado)

            $table->json('opciones')->nullable();   // comportamiento de la integración
            $table->boolean('activa')->default(true);

            // Estado de la conexión
            $table->timestamp('ultimo_ok_at')->nullable();
            $table->text('ultimo_error')->nullable();

            $table->timestamps();

            $table->unique(['empresa_id', 'sistema']);
        });

        // Vínculo entre un registro local y su equivalente en otro sistema
        // (producto ↔ artículo de Comercial, producto ↔ publicación de Mercado, depósito ↔ sucursal, etc.)
        Schema::create('vinculos_externos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->cascadeOnDelete();
            $table->morphs('vinculable');           // vinculable_type + vinculable_id
            $table->string('sistema', 30);
            $table->string('id_externo', 100);
            $table->json('datos')->nullable();
            $table->timestamp('sincronizado_at')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'sistema', 'vinculable_type', 'id_externo'], 'vinculos_externo_unico');
            $table->unique(['vinculable_type', 'vinculable_id', 'sistema'], 'vinculos_local_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculos_externos');
        Schema::dropIfExists('integraciones');
    }
};
