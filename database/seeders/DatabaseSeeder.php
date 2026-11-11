<?php

namespace Database\Seeders;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Superadmin (dueño del SaaS)
        User::updateOrCreate(
            ['email' => env('SEED_SUPERADMIN_EMAIL', 'superadmin@migestion.com.ar')],
            [
                'empresa_id' => null,
                'name' => 'Superadmin',
                'password' => env('SEED_SUPERADMIN_PASSWORD', 'cambiar123'),
                'rol' => User::ROL_SUPERADMIN,
                'activo' => true,
            ]
        );

        // Empresa demo
        $empresa = Empresa::updateOrCreate(
            ['cuit' => '30-71234567-8'],
            [
                'razon_social' => 'Distribuidora Demo S.R.L.',
                'nombre_fantasia' => 'Demo Stock',
                'condicion_iva' => 'responsable_inscripto',
                'localidad' => 'General Pacheco',
                'provincia' => 'Buenos Aires',
                'email' => 'demo@migestion.com.ar',
                'metodo_costeo' => Empresa::COSTEO_PROMEDIO,
                'permite_stock_negativo' => false,
                'usa_lotes' => true,
                'usa_series' => false,
                'activa' => true,
            ]
        );

        // Admin de la empresa demo
        User::updateOrCreate(
            ['email' => 'admin@demo.com'],
            [
                'empresa_id' => $empresa->id,
                'name' => 'Admin Demo',
                'password' => 'cambiar123',
                'rol' => User::ROL_ADMIN,
                'activo' => true,
            ]
        );

        // Operador de la empresa demo
        User::updateOrCreate(
            ['email' => 'operador@demo.com'],
            [
                'empresa_id' => $empresa->id,
                'name' => 'Operador Demo',
                'password' => 'cambiar123',
                'rol' => User::ROL_OPERADOR,
                'activo' => true,
            ]
        );

        // Catálogo de ejemplo (categorías, marcas y productos)
        $this->callWith(DemoCatalogoSeeder::class, ['empresa' => $empresa]);
    }
}
