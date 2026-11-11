<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // NULL = superadmin (dueño del SaaS, ve todas las empresas)
            $table->foreignId('empresa_id')
                ->nullable()
                ->after('id')
                ->constrained('empresas')
                ->restrictOnDelete();

            $table->enum('rol', ['superadmin', 'admin', 'encargado', 'operador', 'consulta'])
                ->default('operador')
                ->after('password');

            $table->boolean('activo')->default(true)->after('rol');
            $table->timestamp('ultimo_acceso')->nullable()->after('activo');

            $table->index(['empresa_id', 'activo']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['empresa_id', 'activo']);
            $table->dropConstrainedForeignId('empresa_id');
            $table->dropColumn(['rol', 'activo', 'ultimo_acceso']);
        });
    }
};
