<?php

namespace Tests\Feature;

use App\Models\Modulo;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermisosApiTest extends TestCase
{
    use RefreshDatabase;

    private function rolOperario(): Rol
    {
        return Rol::where('clave', Rol::CLAVE_OPERARIO)->firstOrFail();
    }

    public function test_super_admin_ve_la_matriz(): void
    {
        $respuesta = $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/permisos')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'roles' => [['id', 'clave', 'nombre', 'es_super', 'modulos']],
                'modulos' => [['clave', 'nombre', 'padre', 'solo_super', 'base']],
            ]]);

        $roles = collect($respuesta->json('data.roles'))->keyBy('clave');
        $this->assertTrue($roles['super_admin']['es_super']);
        $this->assertNotContains('benchmark', $roles['operario']['modulos']);
        $this->assertContains('gastos', $roles['operario']['modulos']);

        $modulos = collect($respuesta->json('data.modulos'))->keyBy('clave');
        $this->assertSame('asistente', $modulos['benchmark']['padre']);
        $this->assertFalse($modulos['benchmark']['base']);
        $this->assertTrue($modulos['gastos']['base']);
        $this->assertTrue($modulos['usuarios']['solo_super']);
    }

    public function test_operario_recibe_403_en_permisos(): void
    {
        $operario = User::factory()->operario()->create();

        $this->actingAs($operario, 'sanctum')->getJson('/api/v1/permisos')->assertForbidden();
        $this->actingAs($operario, 'sanctum')
            ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, ['modulos' => ['benchmark']])
            ->assertForbidden();
    }

    public function test_se_puede_dar_benchmark_al_operario_y_la_api_lo_respeta_de_inmediato(): void
    {
        $operario = User::factory()->operario()->create();
        $super = User::factory()->create();

        $this->actingAs($operario, 'sanctum')->getJson('/api/v1/asistente/benchmark/estadisticas')->assertForbidden();

        $this->actingAs($super, 'sanctum')
            ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, ['modulos' => ['benchmark']])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($operario->fresh(), 'sanctum')
            ->getJson('/api/v1/asistente/benchmark/estadisticas')
            ->assertOk();
    }

    public function test_quitar_benchmark_al_operario_da_403_en_la_siguiente_peticion(): void
    {
        $operario = User::factory()->operario()->create();
        $this->rolOperario()->modulos()->attach(Modulo::where('clave', 'benchmark')->value('id'));
        $super = User::factory()->create();

        $this->actingAs($operario, 'sanctum')->getJson('/api/v1/asistente/benchmark/estadisticas')->assertOk();

        $this->actingAs($super, 'sanctum')
            ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, ['modulos' => []])
            ->assertOk();

        $this->actingAs($operario->fresh(), 'sanctum')
            ->getJson('/api/v1/asistente/benchmark/estadisticas')
            ->assertForbidden();
    }

    public function test_un_put_vacio_no_quita_los_modulos_base_al_operario(): void
    {
        $operario = User::factory()->operario()->create();
        $super = User::factory()->create();

        $this->actingAs($super, 'sanctum')
            ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, ['modulos' => []])
            ->assertOk();

        foreach (['/api/v1/gastos', '/api/v1/aves', '/api/v1/dashboard/resumen', '/api/v1/asistente/conversaciones'] as $uri) {
            $this->actingAs($operario->fresh(), 'sanctum')->getJson($uri)->assertOk();
        }
    }

    public function test_una_clave_base_en_el_body_se_ignora_sin_error(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, ['modulos' => ['gastos', 'benchmark']])
            ->assertOk();

        $claves = $this->rolOperario()->modulos()->pluck('clave')->all();
        $this->assertSame(1, count(array_keys($claves, 'gastos', true)));
        $this->assertContains('benchmark', $claves);
    }

    public function test_no_se_puede_asignar_usuarios_ni_permisos_al_operario(): void
    {
        $super = User::factory()->create();

        foreach (['usuarios', 'permisos'] as $clave) {
            $this->actingAs($super, 'sanctum')
                ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, ['modulos' => [$clave]])
                ->assertStatus(422);
        }

        $this->assertNotContains('usuarios', $this->rolOperario()->modulos()->pluck('clave')->all());
        $this->assertNotContains('permisos', $this->rolOperario()->modulos()->pluck('clave')->all());
    }

    public function test_no_se_edita_el_rol_super_admin(): void
    {
        $rolSuper = Rol::where('clave', Rol::CLAVE_SUPER_ADMIN)->firstOrFail();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/permisos/'.$rolSuper->id, ['modulos' => ['benchmark']])
            ->assertStatus(422);

        $this->assertSame(0, $rolSuper->modulos()->count());
    }

    public function test_clave_inexistente_da_422(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, ['modulos' => ['no_existe']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['modulos.0']);
    }

    public function test_el_body_debe_traer_la_lista_de_modulos(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/permisos/'.$this->rolOperario()->id, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['modulos']);
    }

    public function test_un_rol_inexistente_da_404(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->putJson('/api/v1/permisos/9999', ['modulos' => []])
            ->assertNotFound();
    }
}
