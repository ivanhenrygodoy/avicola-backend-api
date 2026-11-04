<?php

namespace Tests\Feature;

use App\Models\Ave;
use App\Models\Casita;
use App\Models\CtlEspecie;
use App\Models\CtlSexo;
use App\Models\Division;
use App\Models\Granja;
use App\Models\User;
use Database\Seeders\AvesSeeder;
use Database\Seeders\CatalogoAvesSeeder;
use Database\Seeders\InstalacionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AvesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // Sembrar catálogos, instalaciones y las 14 aves iniciales
        $this->seed(CatalogoAvesSeeder::class);
        $this->seed(InstalacionesSeeder::class);
        $this->seed(AvesSeeder::class);
    }

    public function test_puede_obtener_catalogos_de_aves(): void
    {
        $response = $this->getJson('/api/v1/aves/catalogos');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'especies' => [
                        '*' => ['id', 'nombre', 'icono', 'activa'],
                    ],
                    'sexos' => [
                        '*' => ['id', 'nombre'],
                    ],
                    'propositos' => [
                        '*' => ['id', 'nombre', 'activa'],
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data.especies'));
        $this->assertCount(3, $response->json('data.sexos'));
        $this->assertCount(5, $response->json('data.propositos'));
    }

    public function test_puede_obtener_listados_independientes_de_especies_y_sexos(): void
    {
        $respEspecies = $this->getJson('/api/v1/aves/especies');
        $respEspecies->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $respEspecies->json('data'));

        $respSexos = $this->getJson('/api/v1/aves/sexos');
        $respSexos->assertStatus(200)->assertJson(['success' => true]);
        $this->assertCount(3, $respSexos->json('data'));
    }

    public function test_puede_listar_aves_con_paginacion_y_estadisticas(): void
    {
        $response = $this->getJson('/api/v1/aves');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'codigo',
                        'nombre',
                        'id_especie',
                        'id_sexo',
                        'especie' => ['id', 'nombre', 'icono'],
                        'sexo' => ['id', 'nombre'],
                        'raza',
                        'proposito',
                        'estado',
                        'peso_actual_kg',
                        'division' => [
                            'id',
                            'nombre',
                            'casita' => [
                                'id',
                                'nombre',
                            ],
                        ],
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
                'stats' => [
                    'total_aves',
                    'activas_count',
                    'gallinas_count',
                    'chompipes_count',
                    'patos_count',
                ],
            ]);

        $this->assertEquals(14, $response->json('meta.total'));
        $this->assertEquals(10, $response->json('stats.gallinas_count'));
        $this->assertEquals(2, $response->json('stats.chompipes_count'));
        $this->assertEquals(2, $response->json('stats.patos_count'));
    }

    public function test_puede_filtrar_aves_por_especie_y_busqueda(): void
    {
        $chompipe = CtlEspecie::where('nombre', 'Chompipe')->firstOrFail();

        // Filtrar por ID de especie Chompipe
        $responseChompipes = $this->getJson("/api/v1/aves?id_especie={$chompipe->id}");
        $responseChompipes->assertStatus(200);
        $this->assertCount(2, $responseChompipes->json('data'));

        // Filtrar por término de búsqueda (nombre: Canela)
        $responseCanela = $this->getJson('/api/v1/aves?search=Canela');
        $responseCanela->assertStatus(200);
        $this->assertCount(1, $responseCanela->json('data'));
        $this->assertEquals('AVE-001', $responseCanela->json('data.0.codigo'));

        // Filtrar por término de búsqueda por raza (Pichiche)
        $responseRaza = $this->getJson('/api/v1/aves?search=Pichiche');
        $responseRaza->assertStatus(200);
        $this->assertCount(2, $responseRaza->json('data'));

        // Filtrar por sexo
        $macho = CtlSexo::where('nombre', 'Macho')->firstOrFail();
        $responseMachos = $this->getJson("/api/v1/aves?id_sexo={$macho->id}");
        $responseMachos->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count($responseMachos->json('data')));
    }

    public function test_puede_registrar_nueva_ave_sin_division(): void
    {
        $gallina = CtlEspecie::where('nombre', 'Gallina')->firstOrFail();
        $hembra = CtlSexo::where('nombre', 'Hembra')->firstOrFail();

        $payload = [
            'codigo' => 'AVE-099',
            'nombre' => 'Ponedora Nueva',
            'id_especie' => $gallina->id,
            'id_sexo' => $hembra->id,
            'raza' => 'Criolla',
            'proposito' => 'Postura',
            'fecha_ingreso' => '2026-02-01',
            'peso_actual_kg' => 2.10,
        ];

        $response = $this->postJson('/api/v1/aves', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Ave registrada exitosamente.',
                'data' => [
                    'codigo' => 'AVE-099',
                    'nombre' => 'Ponedora Nueva',
                    'id_especie' => $gallina->id,
                    'id_sexo' => $hembra->id,
                ],
            ]);

        $this->assertDatabaseHas('mnt_aves', ['codigo' => 'AVE-099']);
    }

    public function test_falla_al_registrar_ave_con_codigo_duplicado(): void
    {
        $gallina = CtlEspecie::where('nombre', 'Gallina')->firstOrFail();
        $hembra = CtlSexo::where('nombre', 'Hembra')->firstOrFail();

        $payload = [
            'codigo' => 'AVE-001', // Ya existe Canela
            'nombre' => 'Clon',
            'id_especie' => $gallina->id,
            'id_sexo' => $hembra->id,
        ];

        $response = $this->postJson('/api/v1/aves', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_puede_trasladar_ave_entre_divisiones_compatibles(): void
    {
        // Canela (AVE-001, Gallina) está en Casita 1 División 1
        $canela = Ave::where('codigo', 'AVE-001')->firstOrFail();
        $casita1 = Casita::where('nombre', 'Casita 1')->firstOrFail();
        $c1d2 = Division::where('id_casita', $casita1->id)->where('nombre', 'División 2')->firstOrFail();

        $response = $this->postJson("/api/v1/aves/{$canela->id}/trasladar", [
            'id_division_destino' => $c1d2->id,
            'motivo' => 'Rotación de pastoreo y balance de lote',
            'observaciones' => 'Traslado realizado por la mañana',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        $this->assertDatabaseHas('mnt_aves', [
            'id' => $canela->id,
            'id_division' => $c1d2->id,
        ]);

        $this->assertDatabaseHas('mnt_movimientos_aves', [
            'id_ave' => $canela->id,
            'id_division_destino' => $c1d2->id,
            'motivo' => 'Rotación de pastoreo y balance de lote',
        ]);
    }

    public function test_rechaza_traslado_por_incompatibilidad_biologica(): void
    {
        // Pato Lucas (AVE-013, Pato) intenta trasladarse a Casita 1 División 1 (habitada por Gallinas)
        $patoLucas = Ave::where('codigo', 'AVE-013')->firstOrFail();
        $casita1 = Casita::where('nombre', 'Casita 1')->firstOrFail();
        $c1d1 = Division::where('id_casita', $casita1->id)->where('nombre', 'División 1')->firstOrFail();

        $response = $this->postJson("/api/v1/aves/{$patoLucas->id}/trasladar", [
            'id_division_destino' => $c1d1->id,
            'motivo' => 'Intento de mezcla biológica no permitida',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['id_division_destino']);

        // Pato Lucas debe permanecer en su espacio original
        $this->assertNotEquals($c1d1->id, $patoLucas->fresh()->id_division);
    }

    public function test_rechaza_traslado_a_division_inactiva(): void
    {
        $canela = Ave::where('codigo', 'AVE-001')->firstOrFail();
        $casita1 = Casita::where('nombre', 'Casita 1')->firstOrFail();
        $c1d2 = Division::where('id_casita', $casita1->id)->where('nombre', 'División 2')->firstOrFail();

        // Desactivar división destino
        $c1d2->update(['activa' => false]);

        $response = $this->postJson("/api/v1/aves/{$canela->id}/trasladar", [
            'id_division_destino' => $c1d2->id,
            'motivo' => 'Traslado a corral clausurado',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['id_division_destino']);
    }

    public function test_puede_consultar_historial_de_movimientos(): void
    {
        $canela = Ave::where('codigo', 'AVE-001')->firstOrFail();

        $response = $this->getJson("/api/v1/aves/{$canela->id}/movimientos");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'id_ave',
                        'id_division_origen',
                        'id_division_destino',
                        'fecha',
                        'motivo',
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data'));
    }
}
