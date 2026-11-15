<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolesYModulosSeeder;
use Database\Seeders\TemaDefaultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TemaTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $operario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesYModulosSeeder::class);
        $this->seed(TemaDefaultSeeder::class);

        $rolSuper = Rol::where('clave', Rol::CLAVE_SUPER_ADMIN)->firstOrFail();
        $rolOperario = Rol::where('clave', Rol::CLAVE_OPERARIO)->firstOrFail();

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@granja.local',
            'password' => Hash::make('password123'),
            'id_rol' => $rolSuper->id,
            'activo' => true,
        ]);

        $this->operario = User::create([
            'name' => 'Operario Granja',
            'email' => 'operario@granja.local',
            'password' => Hash::make('password123'),
            'id_rol' => $rolOperario->id,
            'activo' => true,
        ]);
    }

    public function test_endpoint_publico_retorna_paleta_clara_y_nocturna_por_defecto(): void
    {
        $response = $this->getJson('/api/v1/tema/activo');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.claro.--avicola-btn-primario-bg', '#244B36')
            ->assertJsonPath('data.nocturno.--avicola-crema', '#0F1713');
    }

    public function test_operario_no_puede_administrar_apariencia(): void
    {
        $response = $this->actingAs($this->operario)
            ->getJson('/api/v1/apariencia/componentes');

        $response->assertStatus(403);
    }

    public function test_super_admin_puede_listar_editar_y_restaurar_componente(): void
    {
        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/apariencia/componentes')
            ->assertStatus(200)
            ->assertJsonCount(9, 'data');

        $update = $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/apariencia/componentes/botones', [
                'modo' => 'claro',
                'aspectos' => [
                    ['aspecto' => 'primario_fondo', 'valor_actual' => '#1e40af'],
                ],
            ]);

        $update->assertStatus(200)
            ->assertJsonPath('data.personalizado', true);

        $this->getJson('/api/v1/tema/activo')
            ->assertJsonPath('data.claro.--avicola-btn-primario-bg', '#1E40AF');

        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/apariencia/componentes/botones/restaurar', ['modo' => 'claro'])
            ->assertStatus(200)
            ->assertJsonPath('data.personalizado', false);

        $this->getJson('/api/v1/tema/activo')
            ->assertJsonPath('data.claro.--avicola-btn-primario-bg', '#244B36');
    }

    public function test_rechaza_hex_invalido_con_422(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->putJson('/api/v1/apariencia/componentes/botones', [
                'modo' => 'claro',
                'aspectos' => [
                    ['aspecto' => 'primario_fondo', 'valor_actual' => 'azul-invalido'],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['aspectos.0.valor_actual']);
    }

    public function test_usuario_puede_guardar_preferencia_de_modo_nocturno(): void
    {
        $this->actingAs($this->operario)
            ->patchJson('/api/v1/auth/preferencia-tema', ['modo_tema' => 'nocturno'])
            ->assertStatus(200)
            ->assertJsonPath('data.modo_tema', 'nocturno');

        $this->actingAs($this->operario)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('data.modo_tema', 'nocturno');
    }
}

