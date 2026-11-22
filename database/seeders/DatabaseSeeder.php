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
        $this->call(TemaDefaultSeeder::class);
        $this->call(EstadoFuenteConocimientoSeeder::class);

        $adminEmail = config('avicola.admin_default_email', env('ADMIN_DEFAULT_EMAIL', 'admin@granja.local'));
        $adminPassword = config('avicola.admin_default_password', env('ADMIN_DEFAULT_PASSWORD'));
        $adminName = config('avicola.admin_default_name', env('ADMIN_DEFAULT_NAME', 'Administrador de Granja'));

        if (empty($adminPassword)) {
            throw new \RuntimeException(
                'Seguridad: La variable ADMIN_DEFAULT_PASSWORD no está configurada en .env. ' .
                'Por seguridad no se permiten contraseñas por defecto en el código fuente. ' .
                'Por favor define ADMIN_DEFAULT_PASSWORD en tu archivo .env antes de ejecutar este seeder.'
            );
        }

        $admin = User::firstOrCreate(
            ['email' => $adminEmail],
            [
                'name' => $adminName,
                'password' => Hash::make($adminPassword),
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
