<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_crea_administrador_con_password_de_entorno(): void
    {
        config(['avicola.admin_default_password' => 'SecretoSeguro2026!']);

        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', config('avicola.admin_default_email'))->first();

        $this->assertNotNull($admin);
        $this->assertTrue(password_verify('SecretoSeguro2026!', $admin->password));
    }

    public function test_database_seeder_lanza_excepcion_si_no_hay_password_configurado(): void
    {
        config(['avicola.admin_default_password' => null]);
        putenv('ADMIN_DEFAULT_PASSWORD=');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ADMIN_DEFAULT_PASSWORD no está configurada');

        $this->seed(DatabaseSeeder::class);
    }
}
