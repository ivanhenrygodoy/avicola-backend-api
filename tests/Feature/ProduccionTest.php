<?php

namespace Tests\Feature;

use App\Models\CtlTipoAlimento;
use App\Models\Division;
use App\Models\User;
use Database\Seeders\CatalogoAlimentosSeeder;
use Database\Seeders\CatalogoInstalacionesSeeder;
use Database\Seeders\InstalacionesSeeder;
use Database\Seeders\ProduccionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProduccionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Division $division;
    protected CtlTipoAlimento $tipoAlimento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // Sembrar instalaciones, catálogo de alimentos y datos iniciales
        $this->seed(CatalogoInstalacionesSeeder::class);
        $this->seed(InstalacionesSeeder::class);
        $this->seed(CatalogoAlimentosSeeder::class);

        $this->division = Division::firstOrFail();
        $this->tipoAlimento = CtlTipoAlimento::firstOrFail();
    }

    public function test_puede_obtener_catalogo_de_tipos_de_alimento(): void
    {
        $response = $this->getJson('/api/v1/catalogos/tipos-alimento');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'nombre', 'descripcion', 'unidad_medida_defecto', 'activo'],
                ],
            ]);

        $this->assertGreaterThanOrEqual(4, count($response->json('data')));
    }

    public function test_puede_registrar_recoleccion_huevos_y_calcula_aprovechables_correctamente(): void
    {
        // 10 recolectados y 2 rotos -> 8 aprovechables
        $payload = [
            'id_division' => $this->division->id,
            'fecha' => '2026-11-05',
            'cantidad_recolectada' => 10,
            'cantidad_rotos' => 2,
            'observaciones' => 'Postura normal de la mañana',
        ];

        $response = $this->postJson('/api/v1/huevos', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'cantidad_recolectada' => 10,
                    'cantidad_rotos' => 2,
                    'cantidad_aprovechables' => 8,
                ],
            ]);

        $this->assertDatabaseHas('mnt_registros_huevos', [
            'id_division' => $this->division->id,
            'cantidad_recolectada' => 10,
            'cantidad_rotos' => 2,
            'cantidad_aprovechables' => 8,
        ]);
    }

    public function test_rechaza_registro_huevos_cuando_rotos_superan_recolectados(): void
    {
        // 5 recolectados y 6 rotos -> debe rechazar con 422
        $payload = [
            'id_division' => $this->division->id,
            'fecha' => '2026-11-05',
            'cantidad_recolectada' => 5,
            'cantidad_rotos' => 6,
        ];

        $response = $this->postJson('/api/v1/huevos', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cantidad_rotos']);
    }

    public function test_rechaza_registro_huevos_con_cantidades_negativas(): void
    {
        $payload = [
            'id_division' => $this->division->id,
            'fecha' => '2026-11-05',
            'cantidad_recolectada' => -1,
            'cantidad_rotos' => 0,
        ];

        $response = $this->postJson('/api/v1/huevos', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cantidad_recolectada']);
    }

    public function test_puede_registrar_suministro_de_alimento(): void
    {
        $payload = [
            'id_division' => $this->division->id,
            'id_tipo_alimento' => $this->tipoAlimento->id,
            'fecha' => '2026-11-05',
            'hora' => '08:00',
            'cantidad' => 2.50,
            'unidad_medida' => 'kg',
            'costo_total' => 2.10,
            'observaciones' => 'Ración de maíz y concentrado',
        ];

        $response = $this->postJson('/api/v1/alimentacion', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id_division' => $this->division->id,
                    'id_tipo_alimento' => $this->tipoAlimento->id,
                    'cantidad' => '2.50',
                    'unidad_medida' => 'kg',
                ],
            ]);

        $this->assertDatabaseHas('mnt_registros_alimentacion', [
            'id_division' => $this->division->id,
            'id_tipo_alimento' => $this->tipoAlimento->id,
            'cantidad' => 2.50,
        ]);
    }

    public function test_rechaza_alimento_con_cantidad_cero_o_negativa(): void
    {
        $payload = [
            'id_division' => $this->division->id,
            'id_tipo_alimento' => $this->tipoAlimento->id,
            'fecha' => '2026-11-05',
            'cantidad' => 0,
        ];

        $response = $this->postJson('/api/v1/alimentacion', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['cantidad']);
    }

    public function test_puede_listar_registros_con_filtros_y_estadisticas(): void
    {
        $this->seed(ProduccionSeeder::class);

        $respHuevos = $this->getJson('/api/v1/huevos');
        $respHuevos->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'total'],
                'stats' => ['total_recolectados', 'total_rotos', 'total_aprovechables', 'porcentaje_rotura'],
            ]);

        $respAlimentacion = $this->getJson('/api/v1/alimentacion');
        $respAlimentacion->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'total'],
                'stats' => ['total_kilos', 'total_costo', 'total_registros'],
            ]);
    }
}

