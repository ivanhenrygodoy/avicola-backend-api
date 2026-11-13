<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProteccionPorModuloTest extends TestCase
{
    use RefreshDatabase;

    private const MENSAJE_403 = 'No tienes permiso para acceder a este módulo.';

    /** @return array<string, array{0: string, 1: string}> */
    public static function rutasPorModulo(): array
    {
        return [
            'dashboard' => ['/api/v1/dashboard/resumen', 'dashboard'],
            'instalaciones' => ['/api/v1/casitas', 'instalaciones'],
            'granjas' => ['/api/v1/granjas', 'instalaciones'],
            'aves' => ['/api/v1/aves', 'aves'],
            'produccion huevos' => ['/api/v1/huevos', 'produccion'],
            'produccion alimentacion' => ['/api/v1/alimentacion', 'produccion'],
            'gastos' => ['/api/v1/gastos', 'gastos'],
            'asistente conversaciones' => ['/api/v1/asistente/conversaciones', 'asistente'],
            'benchmark' => ['/api/v1/asistente/benchmark/estadisticas', 'benchmark'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function catalogosCompartidos(): array
    {
        return [
            ['/api/v1/catalogos/especies'],
            ['/api/v1/catalogos/sexos'],
            ['/api/v1/catalogos/propositos'],
            ['/api/v1/catalogos/tipos-instalacion'],
            ['/api/v1/catalogos/tipos-alimento'],
            ['/api/v1/catalogos/categorias-gastos'],
            ['/api/v1/aves/catalogos'],
            ['/api/v1/aves/especies'],
            ['/api/v1/alimentos/tipos'],
            ['/api/v1/gastos/categorias'],
            ['/api/v1/instalaciones/tipos'],
            ['/api/v1/casitas/tipos'],
        ];
    }

    #[DataProvider('rutasPorModulo')]
    public function test_super_admin_accede_a_todo(string $uri, string $clave): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson($uri)
            ->assertSuccessful();
    }

    #[DataProvider('rutasPorModulo')]
    public function test_operario_accede_a_todo_menos_benchmark(string $uri, string $clave): void
    {
        $respuesta = $this->actingAs(User::factory()->operario()->create(), 'sanctum')->getJson($uri);

        if ($clave === 'benchmark') {
            $respuesta->assertForbidden()->assertExactJson([
                'success' => false,
                'message' => self::MENSAJE_403,
            ]);

            return;
        }

        $respuesta->assertSuccessful();
    }

    #[DataProvider('rutasPorModulo')]
    public function test_usuario_sin_rol_recibe_403_en_todo(string $uri, string $clave): void
    {
        $this->actingAs(User::factory()->sinRol()->create(), 'sanctum')
            ->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('message', self::MENSAJE_403);
    }

    #[DataProvider('rutasPorModulo')]
    public function test_usuario_inactivo_con_token_vivo_recibe_403(string $uri, string $clave): void
    {
        $this->actingAs(User::factory()->inactivo()->create(), 'sanctum')
            ->getJson($uri)
            ->assertForbidden()
            ->assertJsonPath('message', self::MENSAJE_403);
    }

    #[DataProvider('catalogosCompartidos')]
    public function test_los_catalogos_compartidos_siguen_abiertos_a_un_operario(string $uri): void
    {
        $this->actingAs(User::factory()->operario()->create(), 'sanctum')
            ->getJson($uri)
            ->assertSuccessful();
    }

    public function test_sin_autenticar_recibe_401_no_403(): void
    {
        $this->getJson('/api/v1/aves')->assertUnauthorized();
        $this->getJson('/api/v1/asistente/benchmark/estadisticas')->assertUnauthorized();
    }

    public function test_la_depuracion_del_benchmark_tambien_esta_protegida(): void
    {
        $this->actingAs(User::factory()->operario()->create(), 'sanctum')
            ->deleteJson('/api/v1/asistente/benchmark/depuracion')
            ->assertForbidden();
    }
}
