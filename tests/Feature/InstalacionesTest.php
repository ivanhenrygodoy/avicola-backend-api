<?php

namespace Tests\Feature;

use App\Models\Casita;
use App\Models\CtlEspecie;
use App\Models\CtlSexo;
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

    public function test_authenticated_user_can_get_tipos_instalacion_catalog(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/instalaciones/tipos');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'nombre', 'activa'],
                ],
            ]);

        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
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
        $this->assertEquals(1, $response->json('stats.total_casitas'));
        $this->assertEquals(2, $response->json('stats.total_divisiones'));
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

    public function test_can_delete_an_empty_division(): void
    {
        $casita = Casita::first();
        $division = Division::create(['id_casita' => $casita->id, 'nombre' => 'Corral Temporal']);
        $antes = Division::count();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/divisiones/{$division->id}")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('mnt_divisiones', ['id' => $division->id]);
        $this->assertSame($antes - 1, Division::count());
    }

    public function test_cannot_delete_a_division_that_has_birds(): void
    {
        $division = Division::first();
        $division->aves()->create([
            'id_especie' => CtlEspecie::first()?->id ?? CtlEspecie::create(['nombre' => 'Gallina', 'activa' => true])->id,
            'id_sexo' => CtlSexo::first()?->id ?? CtlSexo::create(['nombre' => 'Hembra'])->id,
            'codigo' => 'T-DIV-1',
            'estado' => 'activa',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/divisiones/{$division->id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('mnt_divisiones', ['id' => $division->id]);
    }

    public function test_can_show_and_update_a_division(): void
    {
        $division = Division::first();

        $this->actingAs($this->user)
            ->getJson("/api/v1/divisiones/{$division->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $division->id);

        $this->actingAs($this->user)
            ->putJson("/api/v1/divisiones/{$division->id}", ['nombre' => 'Nombre Actualizado'])
            ->assertStatus(200);

        $this->assertDatabaseHas('mnt_divisiones', ['id' => $division->id, 'nombre' => 'Nombre Actualizado']);
    }

    public function test_deleting_a_missing_division_returns_404(): void
    {
        $this->actingAs($this->user)
            ->deleteJson('/api/v1/divisiones/999999')
            ->assertStatus(404);
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

    public function test_requires_authentication_to_list_granjas(): void
    {
        $this->getJson('/api/v1/granjas')->assertStatus(401);
    }

    public function test_authenticated_user_can_list_granjas_ordered_by_name(): void
    {
        Granja::create(['nombre' => 'Anexo Norte', 'descripcion' => 'Segunda sede']);

        $response = $this->actingAs($this->user)->getJson('/api/v1/granjas');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data' => ['*' => ['id', 'nombre', 'descripcion']]]);

        $nombres = collect($response->json('data'))->pluck('nombre')->all();
        $this->assertSame($nombres, collect($nombres)->sort()->values()->all());
        $this->assertContains('Anexo Norte', $nombres);
        $this->assertSame(Granja::count(), count($nombres));
    }

    public function test_granjas_listing_returns_empty_list_when_there_are_none(): void
    {
        // Orden inverso a las claves foráneas (restrictOnDelete): divisiones → casitas → granjas
        Division::query()->delete();
        Casita::query()->delete();
        Granja::query()->delete();

        $this->actingAs($this->user)->getJson('/api/v1/granjas')
            ->assertStatus(200)
            ->assertJsonPath('data', []);
    }

    public function test_cannot_create_a_casita_in_a_granja_that_does_not_exist(): void
    {
        $inexistente = (Granja::max('id') ?? 0) + 100;

        $this->actingAs($this->user)
            ->postJson('/api/v1/casitas', [
                'id_granja' => $inexistente,
                'nombre' => 'Casita Fantasma',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_granja']);

        $this->assertDatabaseMissing('mnt_casitas', ['nombre' => 'Casita Fantasma']);
    }

    public function test_casita_requires_a_granja(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/casitas', ['nombre' => 'Casita Sin Granja'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_granja']);
    }
}
