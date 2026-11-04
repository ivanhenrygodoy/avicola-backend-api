<?php

namespace Database\Seeders;

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
        User::firstOrCreate(
            ['email' => 'admin@granja.local'],
            [
                'name' => 'Administrador de Granja',
                'password' => Hash::make('password123'),
            ]
        );

        $this->call([
            CatalogoInstalacionesSeeder::class,
            CatalogoAvesSeeder::class,
            InstalacionesSeeder::class,
            AvesSeeder::class,
        ]);
    }
}
