<?php

namespace Tests\Feature;

use App\Models\Modulo;
use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolesYModulosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolesYModulosTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_seeder_es_idempotente(): void
    {
        $this->seed(RolesYModulosSeeder::class);
        $this->seed(RolesYModulosSeeder::class);

        $this->assertSame(2, Rol::count());
        $this->assertSame(9, Modulo::count());
        $this->assertSame(1, Modulo::where('clave', 'benchmark')->count());
    }

    public function test_el_seeder_no_quita_benchmark_si_ya_fue_otorgado(): void
    {
        $operario = Rol::where('clave', Rol::CLAVE_OPERARIO)->firstOrFail();
        $benchmark = Modulo::where('clave', 'benchmark')->firstOrFail();
        $operario->modulos()->attach($benchmark->id);

        $this->seed(RolesYModulosSeeder::class);

        $this->assertContains('benchmark', $operario->fresh()->modulos->pluck('clave')->all());
    }

    public function test_operario_no_tiene_benchmark_usuarios_ni_permisos(): void
    {
        $claves = User::factory()->operario()->create()->clavesPermitidas();

        foreach (['dashboard', 'instalaciones', 'aves', 'produccion', 'gastos', 'asistente'] as $base) {
            $this->assertContains($base, $claves);
        }
        foreach (['benchmark', 'usuarios', 'permisos'] as $prohibido) {
            $this->assertNotContains($prohibido, $claves);
        }
    }

    public function test_super_admin_tiene_todos_los_modulos_activos(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->esSuperAdmin());
        $this->assertEqualsCanonicalizing(
            Modulo::where('activo', true)->pluck('clave')->all(),
            $user->clavesPermitidas()
        );
        $this->assertTrue($user->puedeAcceder('benchmark'));
    }

    public function test_usuario_sin_rol_no_tiene_ningun_modulo(): void
    {
        $user = User::factory()->sinRol()->create();

        $this->assertFalse($user->esSuperAdmin());
        $this->assertSame([], $user->clavesPermitidas());
        $this->assertFalse($user->puedeAcceder('dashboard'));
    }

    public function test_usuario_inactivo_no_tiene_ningun_modulo(): void
    {
        $user = User::factory()->inactivo()->create();

        $this->assertSame([], $user->clavesPermitidas());
        $this->assertFalse($user->puedeAcceder('dashboard'));
    }

    public function test_los_modulos_se_clasifican_en_base_opcional_y_solo_super(): void
    {
        $base = Modulo::where('base', true)->pluck('clave')->all();
        $soloSuper = Modulo::where('solo_super', true)->pluck('clave')->all();

        $this->assertEqualsCanonicalizing(
            ['dashboard', 'instalaciones', 'aves', 'produccion', 'gastos', 'asistente'],
            $base
        );
        $this->assertEqualsCanonicalizing(['usuarios', 'permisos'], $soloSuper);
        $this->assertSame('asistente', Modulo::where('clave', 'benchmark')->firstOrFail()->padre->clave);
    }

    public function test_la_migracion_asigna_super_admin_a_usuarios_existentes(): void
    {
        $sinRol = User::factory()->sinRol()->create();
        $operario = User::factory()->operario()->create();

        $migracion = require database_path('migrations/2026_11_12_110400_asignar_super_admin_a_usuarios_existentes.php');
        $migracion->up();

        $this->assertTrue($sinRol->fresh()->esSuperAdmin());
        $this->assertSame(Rol::CLAVE_OPERARIO, $operario->fresh()->rol->clave);
    }
}
