<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesYModulosSeeder::class);

        $admin = User::firstOrCreate(
            ['email' => 'admin@granja.local'],
            [
                'name' => 'Administrador de Granja',
                'password' => Hash::make('password123'),
            ]
        );

        if ($admin->id_rol === null) {
            $admin->update(['id_rol' => Rol::where('clave', Rol::CLAVE_SUPER_ADMIN)->value('id')]);
        }

        $this->call([
            CatalogoInstalacionesSeeder::class,
            CatalogoAvesSeeder::class,
            CatalogoAlimentosSeeder::class,
            CategoriaGastosSeeder::class,
            InstalacionesSeeder::class,
            AvesSeeder::class,
            ProduccionSeeder::class,
            GastosSeeder::class,
        ]);
    }
}
