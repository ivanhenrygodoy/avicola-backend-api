<?php

namespace Tests\Feature;

use App\Models\FuncionLogAgent;
use App\Models\FuncionLogAgentTool;
use App\Models\Gasto;
use App\Models\User;
use App\Services\Asistente\Benchmark\DepuracionLogsAgenteService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepuracionLogsAgenteTest extends TestCase
{
    use RefreshDatabase;

    private DepuracionLogsAgenteService $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        config(['avicola.timezone' => 'America/El_Salvador']);
        $this->servicio = app(DepuracionLogsAgenteService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearTurno(CarbonImmutable|Carbon $fecha, int $tools = 0): FuncionLogAgent
    {
        $log = FuncionLogAgent::create([
            'uuid_conversacion' => (string) Str::uuid(),
            'mensaje_usuario' => 'Consulta de prueba '.$fecha->toIso8601String(),
            'turnos_historial' => 0,
            'prompt_version' => 'testprompt12',
            'respuesta_agente' => 'Respuesta test',
            'resultado' => 'exitoso',
            'duracion_ms' => 100,
            'created_at' => $fecha,
        ]);

        for ($i = 1; $i <= $tools; $i++) {
            $log->tools()->create([
                'orden' => $i,
                'herramienta' => 'herramienta_test_'.$i,
                'argumentos' => ['param' => 'valor'],
                'exito' => true,
                'duracion_ms' => 50,
            ]);
        }

        return $log;
    }

    public function test_rechaza_meses_fuera_de_la_lista_sin_consultar_ni_borrar(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->crearTurno(now()->subMonths(14), 1);
        $totalInicial = FuncionLogAgent::count();

        $valoresInvalidos = [0, 1, 2, 4, 5, 7, 11, 13, 24, -3];

        foreach ($valoresInvalidos as $invalido) {
            try {
                $this->servicio->vistaPrevia($invalido);
                $this->fail("vistaPrevia debió fallar para meses={$invalido}");
            } catch (InvalidArgumentException) {
                // Éxito esperado
            }

            try {
                $this->servicio->depurar($invalido);
                $this->fail("depurar debió fallar para meses={$invalido}");
            } catch (InvalidArgumentException) {
                // Éxito esperado
            }
        }

        $this->assertSame($totalInicial, FuncionLogAgent::count());
        $this->assertSame($totalInicial, FuncionLogAgentTool::count());
    }

    public function test_borra_solo_lo_anterior_al_corte_con_sus_herramientas(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $viejo = $this->crearTurno(now()->subMonths(13), 2);
        $reciente = $this->crearTurno(now()->subMonths(2), 1);

        $resultado = $this->servicio->depurar(12);

        $this->assertSame(12, $resultado['meses']);
        $this->assertSame(1, $resultado['turnos']);
        $this->assertSame(2, $resultado['tools']);
        $this->assertSame(1, $resultado['conservados']);

        $this->assertFalse(FuncionLogAgent::where('id', $viejo->id)->exists());
        $this->assertFalse(FuncionLogAgentTool::where('id_funcion_log_agent', $viejo->id)->exists());

        $this->assertTrue(FuncionLogAgent::where('id', $reciente->id)->exists());
        $this->assertSame(1, FuncionLogAgentTool::where('id_funcion_log_agent', $reciente->id)->count());
    }

    public function test_el_borde_del_corte_es_exacto_para_3_6_y_12_meses(): void
    {
        $zona = 'America/El_Salvador';
        Carbon::setTestNow('2026-11-10 12:00:00');

        foreach ([3, 6, 12] as $meses) {
            FuncionLogAgentTool::query()->delete();
            FuncionLogAgent::query()->delete();

            $corte = CarbonImmutable::now($zona)->subMonthsNoOverflow($meses)->setTimezone('UTC');

            $viejo = $this->crearTurno($corte->subMinute(), 1);
            $nuevo = $this->crearTurno($corte->addMinute(), 1);

            $resultado = $this->servicio->depurar($meses);

            $this->assertSame(1, $resultado['turnos'], "Fallo en conteo de turnos para {$meses} meses");
            $this->assertFalse(FuncionLogAgent::where('id', $viejo->id)->exists());
            $this->assertTrue(FuncionLogAgent::where('id', $nuevo->id)->exists());
        }
    }

    public function test_el_corte_no_desborda_a_fin_de_mes(): void
    {
        Carbon::setTestNow('2026-08-31 12:00:00');

        $resultado = $this->servicio->vistaPrevia(6);

        // 31 de agosto menos 6 meses sin desborde en año 2026 (no bisiesto) es 28 de febrero
        $this->assertSame('28-02-2026', $resultado['corte']);
    }

    public function test_la_vista_previa_no_borra_y_coincide_con_el_borrado(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->crearTurno(now()->subMonths(7), 2);
        $this->crearTurno(now()->subMonths(8), 1);
        $this->crearTurno(now()->subMonths(1), 3);

        $vista = $this->servicio->vistaPrevia(6);

        $this->assertSame(6, $vista['meses']);
        $this->assertSame(2, $vista['turnos']);
        $this->assertSame(3, $vista['tools']);
        $this->assertSame(1, $vista['conservados']);
        $this->assertSame(3, FuncionLogAgent::count());
        $this->assertSame(6, FuncionLogAgentTool::count());

        $depuracion = $this->servicio->depurar(6);

        $this->assertSame($vista['meses'], $depuracion['meses']);
        $this->assertSame($vista['corte'], $depuracion['corte']);
        $this->assertSame($vista['turnos'], $depuracion['turnos']);
        $this->assertSame($vista['tools'], $depuracion['tools']);
        $this->assertSame($vista['conservados'], $depuracion['conservados']);
        $this->assertSame(1, FuncionLogAgent::count());
        $this->assertSame(3, FuncionLogAgentTool::count());
    }

    public function test_conservados_es_lo_que_queda_despues_de_depurar(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->crearTurno(now()->subMonths(15), 1);
        $this->crearTurno(now()->subMonths(14), 1);
        $this->crearTurno(now()->subMonths(2), 1);
        $this->crearTurno(now()->subMonths(1), 1);

        $resultado = $this->servicio->depurar(12);

        $this->assertSame(2, $resultado['conservados']);
        $this->assertSame(2, FuncionLogAgent::count());
    }

    public function test_borra_por_lotes_sin_dejar_herramientas_huerfanas(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        for ($i = 0; $i < 5; $i++) {
            $this->crearTurno(now()->subMonths(4), 3);
        }
        $this->crearTurno(now()->subMonths(1), 2);

        // Lote de tamaño 2 para forzar múltiples iteraciones y probar integridad de FK
        $resultado = $this->servicio->depurar(3, tamanoLote: 2);

        $this->assertSame(5, $resultado['turnos']);
        $this->assertSame(15, $resultado['tools']);
        $this->assertSame(1, $resultado['conservados']);
        $this->assertSame(1, FuncionLogAgent::count());
        $this->assertSame(2, FuncionLogAgentTool::count());
    }

    public function test_es_idempotente(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->crearTurno(now()->subMonths(7), 2);

        $primera = $this->servicio->depurar(6);
        $this->assertSame(1, $primera['turnos']);
        $this->assertSame(2, $primera['tools']);

        $segunda = $this->servicio->depurar(6);
        $this->assertSame(0, $segunda['turnos']);
        $this->assertSame(0, $segunda['tools']);
        $this->assertSame(0, $segunda['conservados']);
    }

    public function test_no_toca_otras_tablas(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $this->crearTurno(now()->subMonths(14), 2);

        $usuariosInicial = User::count();
        $gastosInicial = Gasto::count();

        $this->servicio->depurar(12);

        $this->assertSame($usuariosInicial, User::count());
        $this->assertSame($gastosInicial, Gasto::count());
    }

    public function test_con_la_bd_vacia_devuelve_ceros(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');

        $vista = $this->servicio->vistaPrevia(3);
        $this->assertSame(0, $vista['turnos']);
        $this->assertSame(0, $vista['tools']);
        $this->assertSame(0, $vista['conservados']);

        $depuracion = $this->servicio->depurar(3);
        $this->assertSame(0, $depuracion['turnos']);
        $this->assertSame(0, $depuracion['tools']);
        $this->assertSame(0, $depuracion['conservados']);
    }

    public function test_los_endpoints_requieren_autenticacion(): void
    {
        $this->getJson('/api/v1/asistente/benchmark/depuracion?meses=3')->assertStatus(401);
        $this->deleteJson('/api/v1/asistente/benchmark/depuracion?meses=3')->assertStatus(401);
    }

    public function test_validacion_rechaza_meses_invalidos_con_422_y_no_borra(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        $this->crearTurno(now()->subMonths(14), 1);
        $totalInicial = FuncionLogAgent::count();

        $valoresInvalidos = ['', 'abc', 0, 1, 2, 4, 5, 7, 13, -3];

        foreach ($valoresInvalidos as $invalido) {
            $this->getJson("/api/v1/asistente/benchmark/depuracion?meses={$invalido}")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['meses']);

            $this->deleteJson("/api/v1/asistente/benchmark/depuracion?meses={$invalido}")
                ->assertStatus(422)
                ->assertJsonValidationErrors(['meses']);
        }

        $this->assertSame($totalInicial, FuncionLogAgent::count());
    }

    public function test_la_vista_previa_devuelve_conteos_y_no_borra(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        $this->crearTurno(now()->subMonths(7), 2);
        $this->crearTurno(now()->subMonths(1), 1);

        $response = $this->getJson('/api/v1/asistente/benchmark/depuracion?meses=6');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'meses' => 6,
                    'turnos' => 1,
                    'tools' => 2,
                    'conservados' => 1,
                ],
            ]);

        $this->assertSame(2, FuncionLogAgent::count());
    }

    public function test_delete_elimina_lo_antiguo_conserva_lo_reciente_y_responde_el_formato_estandar(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        $viejo = $this->crearTurno(now()->subMonths(13), 2);
        $reciente = $this->crearTurno(now()->subMonths(2), 1);

        $response = $this->deleteJson('/api/v1/asistente/benchmark/depuracion?meses=12');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Se eliminaron 1 interacciones del historial.',
                'data' => [
                    'meses' => 12,
                    'turnos' => 1,
                    'tools' => 2,
                    'conservados' => 1,
                ],
            ]);

        $this->assertFalse(FuncionLogAgent::where('id', $viejo->id)->exists());
        $this->assertTrue(FuncionLogAgent::where('id', $reciente->id)->exists());
    }

    public function test_delete_sin_registros_antiguos_responde_200_con_ceros_y_mensaje(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        $response = $this->deleteJson('/api/v1/asistente/benchmark/depuracion?meses=12');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'meses' => 12,
                    'turnos' => 0,
                    'tools' => 0,
                    'conservados' => 0,
                ],
            ]);
        $this->assertStringContainsString('No había interacciones anteriores a', $response->json('message'));
    }

    public function test_la_limpieza_deja_rastro_con_el_usuario_y_sin_contenido_de_mensajes(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        Log::spy();

        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        $this->crearTurno(now()->subMonths(14), 1);

        $this->deleteJson('/api/v1/asistente/benchmark/depuracion?meses=12')->assertOk();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function ($mensaje, $contexto) use ($usuario) {
                $idEsperado = $usuario->id_usuario ?? $usuario->id;
                $esAdvertencia = str_contains($mensaje, '[AviaCore] Limpieza de histórico del Benchmark');
                $idCorrecto = ($contexto['id_usuario'] ?? null) === $idEsperado;
                $mesesCorrecto = ($contexto['meses'] ?? null) === 12;
                $turnosCorrecto = ($contexto['turnos'] ?? null) === 1;

                // Ningún contexto contiene texto de mensajes de usuario
                $sinTextoConfidencial = ! str_contains(json_encode($contexto), 'Consulta de prueba');

                return $esAdvertencia && $idCorrecto && $mesesCorrecto && $turnosCorrecto && $sinTextoConfidencial;
            });
    }

    public function test_los_endpoints_de_lectura_del_benchmark_siguen_igual(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        $log = $this->crearTurno(now()->subMonths(1), 1);

        $this->getJson('/api/v1/asistente/benchmark/estadisticas?dias=7')->assertOk();
        $this->getJson('/api/v1/asistente/benchmark/logs')->assertOk();
        $this->getJson("/api/v1/asistente/benchmark/logs/{$log->id}")->assertOk();
    }
}
