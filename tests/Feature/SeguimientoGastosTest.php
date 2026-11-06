<?php

namespace Tests\Feature;

use App\Models\Ave;
use App\Models\CtlCategoriaGasto;
use App\Models\Division;
use App\Models\Gasto;
use App\Models\ObservacionAve;
use App\Models\RegistroPeso;
use App\Models\User;
use Database\Seeders\AvesSeeder;
use Database\Seeders\CatalogoAvesSeeder;
use Database\Seeders\CategoriaGastosSeeder;
use Database\Seeders\InstalacionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeguimientoGastosTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Ave $ave;
    private Division $division;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->seed(CatalogoAvesSeeder::class);
        $this->seed(InstalacionesSeeder::class);
        $this->seed(AvesSeeder::class);
        $this->seed(CategoriaGastosSeeder::class);

        $this->ave = Ave::first();
        $this->division = Division::first();
    }

    public function test_puede_registrar_y_listar_pesos_de_un_ave(): void
    {
        $response = $this->postJson("/api/v1/aves/{$this->ave->id}/pesos", [
            'fecha' => '2026-11-01',
            'peso_kg' => 2.15,
            'observaciones' => 'Pesaje de control',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.peso_kg', '2.15')
            ->assertJsonPath('ave.peso_actual_kg', '2.15');

        $this->assertDatabaseHas('mnt_registros_peso', [
            'id_ave' => $this->ave->id,
            'peso_kg' => 2.15,
        ]);

        $this->ave->refresh();
        $this->assertEquals(2.15, $this->ave->peso_actual_kg);

        // Listar pesos
        $listResponse = $this->getJson("/api/v1/aves/{$this->ave->id}/pesos");

        $listResponse->assertOk()
            ->assertJsonPath('success', true);
        $this->assertGreaterThanOrEqual(1, count($listResponse->json('data')));
    }

    public function test_eliminar_peso_recalcula_peso_actual(): void
    {
        $peso1 = RegistroPeso::create([
            'id_ave' => $this->ave->id,
            'fecha' => '2026-10-01',
            'peso_kg' => 1.80,
        ]);

        $peso2 = RegistroPeso::create([
            'id_ave' => $this->ave->id,
            'fecha' => '2026-11-01',
            'peso_kg' => 2.40,
        ]);

        $this->ave->refresh();
        $this->assertEquals(2.40, $this->ave->peso_actual_kg);

        // Eliminar el segundo pesaje
        $deleteResponse = $this->deleteJson("/api/v1/pesos/{$peso2->id}");

        $deleteResponse->assertOk()
            ->assertJsonPath('success', true);

        $this->ave->refresh();
        $this->assertEquals(1.80, $this->ave->peso_actual_kg);
    }

    public function test_puede_registrar_y_listar_observaciones_sanitarias(): void
    {
        $response = $this->postJson("/api/v1/aves/{$this->ave->id}/observaciones", [
            'fecha' => '2026-11-05',
            'tipo' => 'Salud',
            'titulo' => 'Revisión ocular',
            'descripcion' => 'Ojos limpios y brillantes sin anomalías.',
            'accion_tomada' => 'Ninguna requerida.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.titulo', 'Revisión ocular');

        $this->assertDatabaseHas('mnt_observaciones_aves', [
            'id_ave' => $this->ave->id,
            'titulo' => 'Revisión ocular',
        ]);

        // Listar observaciones
        $listResponse = $this->getJson("/api/v1/aves/{$this->ave->id}/observaciones");

        $listResponse->assertOk();
        $this->assertGreaterThanOrEqual(1, count($listResponse->json('data')));

        // Eliminar observación
        $id = $response->json('data.id');
        $this->deleteJson("/api/v1/observaciones/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('mnt_observaciones_aves', [
            'id' => $id,
        ]);
    }

    public function test_puede_listar_categorias_de_gastos(): void
    {
        $response = $this->getJson('/api/v1/catalogos/categorias-gastos');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'nombre', 'activa']]]);
    }

    public function test_crud_gastos_operativos_con_estadisticas(): void
    {
        $cat = CtlCategoriaGasto::first();

        // 1. Crear gasto
        $createResponse = $this->postJson('/api/v1/gastos', [
            'id_categoria_gasto' => $cat->id,
            'id_division' => $this->division->id,
            'fecha' => '2026-11-05',
            'monto' => 45.50,
            'concepto' => 'Compra de vitaminas hidrosolubles',
            'proveedor' => 'Veterinaria El Campo',
            'observaciones' => 'Dosis para 2 semanas',
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.monto', '45.50');

        $idGasto = $createResponse->json('data.id');

        // 2. Listar gastos
        $listResponse = $this->getJson('/api/v1/gastos');

        $listResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('stats.total_gastos', 45.5)
            ->assertJsonCount(1, 'data');

        // 3. Actualizar gasto
        $updateResponse = $this->putJson("/api/v1/gastos/{$idGasto}", [
            'id_categoria_gasto' => $cat->id,
            'id_division' => $this->division->id,
            'fecha' => '2026-11-05',
            'monto' => 50.00,
            'concepto' => 'Compra de vitaminas hidrosolubles reforzadas',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('data.monto', '50.00');

        // 4. Eliminar gasto
        $deleteResponse = $this->deleteJson("/api/v1/gastos/{$idGasto}");

        $deleteResponse->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('mnt_gastos', ['id' => $idGasto]);
    }
}
