<?php

namespace Tests\Feature;

use App\Models\FuncionLogAgent;
use App\Models\FuncionLogAgentTool;
use App\Models\User;
use Database\Seeders\BenchmarkDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BenchmarkLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabla_log_no_tiene_columnas_de_usuario(): void
    {
        $columnas = Schema::getColumnListing('mnt_funcion_log_agent');

        $this->assertNotEmpty($columnas);
        foreach (['id_usuario', 'user_id', 'usuario', 'email'] as $prohibida) {
            $this->assertNotContains($prohibida, $columnas);
        }
    }

    public function test_demo_seeder_es_idempotente_y_cubre_los_cuatro_resultados(): void
    {
        $this->seed(BenchmarkDemoSeeder::class);
        $primera = FuncionLogAgent::count();
        $herramientas = FuncionLogAgentTool::count();

        $this->seed(BenchmarkDemoSeeder::class);

        $this->assertGreaterThanOrEqual(25, $primera);
        $this->assertSame($primera, FuncionLogAgent::count());
        $this->assertSame($herramientas, FuncionLogAgentTool::count());
        $this->assertEqualsCanonicalizing(
            ['exitoso', 'fast_path', 'bloqueado_guard', 'error'],
            FuncionLogAgent::query()->distinct()->pluck('resultado')->all()
        );
        $this->assertTrue(FuncionLogAgent::where('mensaje_usuario', 'suma 2+3')->where('resultado', 'bloqueado_guard')->exists());
        $this->assertGreaterThan(0, FuncionLogAgentTool::count());
    }

    public function test_demo_seeder_marca_sus_datos_para_poder_borrarlos_y_no_toca_otras_tablas(): void
    {
        $usuarios = User::count();

        $this->seed(BenchmarkDemoSeeder::class);

        $this->assertSame($usuarios, User::count());
        $this->assertSame(
            FuncionLogAgent::count(),
            FuncionLogAgent::whereIn('uuid_conversacion', BenchmarkDemoSeeder::UUIDS_DEMO)->count()
        );
        foreach (FuncionLogAgent::pluck('created_at') as $fecha) {
            $this->assertTrue($fecha->lessThanOrEqualTo(now()));
        }
    }

    public function test_modelo_persiste_turno_con_tools(): void
    {
        $log = FuncionLogAgent::create([
            'mensaje_usuario' => '¿cuántos huevos hay?',
            'turnos_historial' => 0,
            'resultado' => FuncionLogAgent::RESULTADO_EXITOSO,
            'respuesta_agente' => 'Hay 10.',
            'guards_aplicados' => [['guard' => 'DateFormatGuard', 'fase' => 'salida', 'accion' => 'modificacion', 'detalle' => '']],
            'guards_nombres' => '|DateFormatGuard|',
            'tokens_prompt' => 10,
            'tokens_respuesta' => 5,
            'tokens_total' => 15,
            'iteraciones' => 2,
            'duracion_ms' => 120,
            'modelo' => 'gemini-test',
        ]);

        $log->tools()->create(['orden' => 2, 'herramienta' => 'b_tool', 'argumentos' => ['mes' => 11], 'tablas_consultadas' => ['mnt_gastos'], 'exito' => true, 'duracion_ms' => 5]);
        $log->tools()->create(['orden' => 1, 'herramienta' => 'a_tool', 'argumentos' => [], 'tablas_consultadas' => [], 'exito' => false, 'duracion_ms' => 3]);

        $fresco = FuncionLogAgent::with('tools')->findOrFail($log->id);

        $this->assertSame(['a_tool', 'b_tool'], $fresco->tools->pluck('herramienta')->all());
        $this->assertIsArray($fresco->guards_aplicados);
        $this->assertSame('DateFormatGuard', $fresco->guards_aplicados[0]['guard']);
        $this->assertInstanceOf(FuncionLogAgentTool::class, $fresco->tools->first());
        $this->assertSame(['mnt_gastos'], $fresco->tools->last()->tablas_consultadas);
        $this->assertTrue($fresco->tools->last()->exito);
    }
}
