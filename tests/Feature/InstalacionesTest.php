<?php

namespace Tests\Feature;

use App\Models\Casita;
use App\Models\Division;
use App\Models\Granja;
use App\Models\User;
use Database\Seeders\InstalacionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstalacionesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->seed(InstalacionesSeeder::class);
    }

    public function test_authenticated_user_can_list_casitas_with_divisions_and_pagination(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/casitas?per_page=5');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
                'stats' => ['total_casitas', 'total_divisiones', 'divisiones_activas'],
            ])
            ->assertJsonCount(3, 'data'); // 3 casitas iniciales

        // Comprobamos que traiga las divisiones anidadas
        $casita1 = collect($response->json('data'))->firstWhere('nombre', 'Casita 1');
        $this->assertNotNull($casita1);
        $this->assertCount(2, $casita1['divisiones']); // Casita 1 tiene 2 divisiones
    }

    public function test_authenticated_user_can_filter_casitas_by_name(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/casitas?buscar=Casita+1');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->assertEquals('Casita 1', $response->json('data.0.nombre'));
    }

    public function test_authenticated_user_can_toggle_casita_active_state(): void
    {
        $casita = Casita::first();
        $initialState = $casita->activa;

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/casitas/{$casita->id}/toggle-activa");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $casita->id,
                    'activa' => ! $initialState,
                ],
            ]);

        $this->assertDatabaseHas('mnt_casitas', [
            'id' => $casita->id,
            'activa' => ! $initialState,
        ]);
    }

    public function test_authenticated_user_can_create_a_casita(): void
    {
        $granja = Granja::first();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/casitas', [
                'id_granja' => $granja->id,
                'nombre' => 'Casita Cuarentena',
                'tipo' => 'Aislamiento',
                'descripcion' => 'Espacio temporal para chequeos de salud',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'nombre' => 'Casita Cuarentena',
                    'id_granja' => $granja->id,
                ],
            ]);

        $this->assertDatabaseHas('mnt_casitas', [
            'nombre' => 'Casita Cuarentena',
            'id_granja' => $granja->id,
        ]);
    }

    public function test_authenticated_user_can_create_a_division_in_casita(): void
    {
        $casita = Casita::first();

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/divisiones', [
                'id_casita' => $casita->id,
                'nombre' => 'División Extra 3',
                'descripcion' => 'Subdivisión ampliada',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'nombre' => 'División Extra 3',
                    'id_casita' => $casita->id,
                ],
            ]);

        $this->assertDatabaseHas('mnt_divisiones', [
            'nombre' => 'División Extra 3',
            'id_casita' => $casita->id,
        ]);
    }

    public function test_authenticated_user_can_toggle_division_active_state(): void
    {
        $division = Division::first();
        $initialState = $division->activa;

        $response = $this->actingAs($this->user)
            ->patchJson("/api/v1/divisiones/{$division->id}/toggle-activa");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $division->id,
                    'activa' => ! $initialState,
                ],
            ]);

        $this->assertDatabaseHas('mnt_divisiones', [
            'id' => $division->id,
            'activa' => ! $initialState,
        ]);
    }

    public function test_cannot_delete_casita_with_existing_divisions(): void
    {
        $casita = Casita::has('divisiones')->first();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/casitas/{$casita->id}");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'No se puede eliminar la casita porque tiene divisiones asociadas.',
            ]);

        $this->assertDatabaseHas('mnt_casitas', [
            'id' => $casita->id,
        ]);
    }
}

