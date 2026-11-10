<?php

namespace Tests\Feature;

use App\Models\FuncionLogAgent;
use App\Models\User;
use App\Services\Asistente\Benchmark\FuncionLogAgentService;
use App\Services\Asistente\PromptComposer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BenchmarkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['avicola.timezone' => 'America/El_Salvador']);
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  array<int, array<string, mixed>>  $tools
     */
    private function log(array $atributos = [], array $tools = []): FuncionLogAgent
    {
        $log = FuncionLogAgent::create(array_merge([
            'mensaje_usuario' => '¿cuántas aves hay?',
            'turnos_historial' => 0,
            'prompt_version' => FuncionLogAgentService::versionPromptVigente(),
            'respuesta_agente' => 'Hay 12 aves.',
            'resultado' => FuncionLogAgent::RESULTADO_EXITOSO,
            'tokens_prompt' => 100,
            'tokens_respuesta' => 20,
            'tokens_total' => 120,
            'iteraciones' => 1,
            'duracion_ms' => 400,
            'modelo' => 'gemini-test',
            'created_at' => Carbon::parse('2026-11-10 12:00:00', 'UTC'),
        ], $atributos));

        foreach ($tools as $i => $tool) {
            $log->tools()->create(array_merge(['orden' => $i + 1, 'argumentos' => [], 'tablas_consultadas' => [], 'exito' => true, 'duracion_ms' => 5], $tool));
        }

        return $log;
    }

    private function autenticar(): User
    {
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    public function test_endpoints_requieren_autenticacion(): void
    {
        $log = $this->log();

        $this->getJson('/api/v1/asistente/benchmark/estadisticas')->assertStatus(401);
        $this->getJson('/api/v1/asistente/benchmark/logs')->assertStatus(401);
        $this->getJson("/api/v1/asistente/benchmark/logs/{$log->id}")->assertStatus(401);
    }

    public function test_estadisticas_sin_datos_rellena_dias_y_no_divide_por_cero(): void
    {
        $this->autenticar();

        $response = $this->getJson('/api/v1/asistente/benchmark/estadisticas?dias=7')->assertOk();

        $response->assertJsonPath('data.totales.interacciones', 0)
            ->assertJsonPath('data.totales.tasa_exito', 0)
            ->assertJsonPath('data.totales.tokens_total', 0)
            ->assertJsonPath('data.totales.tokens_promedio', 0)
            ->assertJsonPath('data.totales.latencia_promedio_ms', 0)
            ->assertJsonPath('data.rango.desde', '2026-11-04')
            ->assertJsonPath('data.rango.hasta', '2026-11-10');
        $serie = $response->json('data.serie');
        $this->assertCount(7, $serie);
        $this->assertSame('2026-11-04', $serie[0]['fecha']);
        $this->assertSame('2026-11-10', $serie[6]['fecha']);
        $this->assertSame(0, $serie[3]['exitosas']);
        $this->assertSame([], $response->json('data.top_guards'));
        $this->assertSame([], $response->json('data.top_tools'));
        $this->assertCount(4, $response->json('data.por_resultado'));
    }

    public function test_estadisticas_cuenta_por_resultado_tokens_y_latencia(): void
    {
        $this->autenticar();
        $this->log(['resultado' => 'exitoso', 'tokens_prompt' => 100, 'tokens_respuesta' => 20, 'tokens_total' => 120, 'duracion_ms' => 400]);
        $this->log(['resultado' => 'fast_path', 'tokens_prompt' => 0, 'tokens_respuesta' => 0, 'tokens_total' => 0, 'duracion_ms' => 20]);
        $this->log(['resultado' => 'bloqueado_guard', 'tokens_prompt' => 0, 'tokens_respuesta' => 0, 'tokens_total' => 0, 'duracion_ms' => 30, 'guards_nombres' => '|MathematicalOffloadingGuard|', 'guards_aplicados' => [['guard' => 'MathematicalOffloadingGuard', 'fase' => 'entrada', 'accion' => 'bloqueo', 'detalle' => '']]]);
        $this->log(['resultado' => 'error', 'tokens_prompt' => 0, 'tokens_respuesta' => 0, 'tokens_total' => 0, 'duracion_ms' => 50, 'error_mensaje' => 'x']);
        $this->log([], [['herramienta' => 'consultar_gastos_periodo'], ['herramienta' => 'consultar_gastos_periodo']]);

        $data = $this->getJson('/api/v1/asistente/benchmark/estadisticas?dias=7')->assertOk()->json('data');

        $this->assertSame(5, $data['totales']['interacciones']);
        $this->assertSame(2, $data['totales']['exitosas'] - 1); // exitoso x2 (los fast_path se cuentan aparte)
        $this->assertSame(1, $data['totales']['fast_path']);
        $this->assertSame(1, $data['totales']['bloqueadas']);
        $this->assertSame(1, $data['totales']['errores']);
        $this->assertSame(60.0, (float) $data['totales']['tasa_exito']); // (2 exitosos + 1 fast_path) / 5
        $this->assertSame(240, $data['totales']['tokens_total']);
        $this->assertSame(48, $data['totales']['tokens_promedio']);
        $this->assertSame(180, $data['totales']['latencia_promedio_ms']);
        $this->assertSame([['guard' => 'MathematicalOffloadingGuard', 'total' => 1]], $data['top_guards']);
        $this->assertSame([['herramienta' => 'consultar_gastos_periodo', 'total' => 2]], $data['top_tools']);
        $porResultado = collect($data['por_resultado'])->pluck('total', 'resultado')->all();
        $this->assertSame(['exitoso' => 2, 'fast_path' => 1, 'bloqueado_guard' => 1, 'error' => 1], $porResultado);
        $dia = collect($data['serie'])->firstWhere('fecha', '2026-11-10');
        $this->assertSame(3, $dia['exitosas']);
        $this->assertSame(1, $dia['bloqueadas']);
        $this->assertSame(1, $dia['errores']);
        $this->assertSame(200, $dia['tokens_prompt']);
        $this->assertSame(40, $dia['tokens_respuesta']);
    }

    public function test_serie_agrupa_por_dia_en_zona_horaria_local_y_excluye_lo_anterior_al_rango(): void
    {
        $this->autenticar();
        // 03:30 UTC del 10 = 21:30 del 09 en El Salvador (UTC-6)
        $this->log(['created_at' => Carbon::parse('2026-11-10 03:30:00', 'UTC')]);
        // Fuera del rango de 7 días (hace 20 días)
        $this->log(['created_at' => Carbon::parse('2026-10-21 12:00:00', 'UTC')]);

        $data = $this->getJson('/api/v1/asistente/benchmark/estadisticas?dias=7')->assertOk()->json('data');

        $this->assertSame(1, $data['totales']['interacciones']);
        $this->assertSame(1, collect($data['serie'])->firstWhere('fecha', '2026-11-09')['exitosas']);
        $this->assertSame(0, collect($data['serie'])->firstWhere('fecha', '2026-11-10')['exitosas']);
    }

    public function test_estadisticas_de_30_dias_incluyen_30_puntos(): void
    {
        $this->autenticar();

        $this->assertCount(30, $this->getJson('/api/v1/asistente/benchmark/estadisticas?dias=30')->json('data.serie'));
    }

    public function test_validacion_rechaza_parametros_invalidos(): void
    {
        $this->autenticar();

        $this->getJson('/api/v1/asistente/benchmark/estadisticas?dias=15')->assertStatus(422)->assertJsonValidationErrors(['dias']);
        $this->getJson('/api/v1/asistente/benchmark/logs?resultado=inventado')->assertStatus(422)->assertJsonValidationErrors(['resultado']);
        $this->getJson('/api/v1/asistente/benchmark/logs?fecha_inicio=ayer')->assertStatus(422)->assertJsonValidationErrors(['fecha_inicio']);
        $this->getJson('/api/v1/asistente/benchmark/logs?fecha_inicio=2026-11-10&fecha_fin=2026-11-01')->assertStatus(422)->assertJsonValidationErrors(['fecha_fin']);
        $this->getJson('/api/v1/asistente/benchmark/logs?per_page=1000')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    }

    public function test_logs_pagina_con_meta_y_stats_y_orden_descendente(): void
    {
        $this->autenticar();
        foreach (range(1, 7) as $i) {
            $this->log(['mensaje_usuario' => "mensaje {$i}", 'created_at' => Carbon::parse('2026-11-10 12:00:00', 'UTC')->addMinutes($i)]);
        }
        $this->log(['resultado' => 'error']);
        $this->log(['resultado' => 'bloqueado_guard']);
        $this->log(['resultado' => 'fast_path']);

        $response = $this->getJson('/api/v1/asistente/benchmark/logs?per_page=5&page=2')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 10)
            ->assertJsonPath('meta.from', 6)
            ->assertJsonPath('meta.to', 10)
            ->assertJsonPath('stats.total', 10)
            ->assertJsonPath('stats.exitosos', 8)
            ->assertJsonPath('stats.bloqueados', 1)
            ->assertJsonPath('stats.errores', 1);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_logs_item_incluye_resumen_sin_datos_de_usuario(): void
    {
        $this->autenticar();
        $this->log([
            'created_at' => Carbon::parse('2026-11-10 12:30:00', 'UTC'),
            'guards_nombres' => '|DateFormatGuard|ContextTamperingGuard|',
        ], [['herramienta' => 'consultar_gastos_periodo'], ['herramienta' => 'consultar_inventario_aves']]);

        $item = $this->getJson('/api/v1/asistente/benchmark/logs')->assertOk()->json('data.0');

        $this->assertSame('10-11-2026 06:30', $item['created_at']);
        $this->assertSame('¿cuántas aves hay?', $item['mensaje_usuario']);
        $this->assertSame('exitoso', $item['resultado']);
        $this->assertSame(120, $item['tokens_total']);
        $this->assertSame(400, $item['duracion_ms']);
        $this->assertSame(['DateFormatGuard', 'ContextTamperingGuard'], $item['guards']);
        $this->assertSame(['consultar_gastos_periodo', 'consultar_inventario_aves'], $item['herramientas']);
    }

    public function test_logs_filtra_por_resultado(): void
    {
        $this->autenticar();
        $this->log(['resultado' => 'exitoso']);
        $this->log(['resultado' => 'error']);

        $response = $this->getJson('/api/v1/asistente/benchmark/logs?resultado=error')->assertOk();

        $this->assertSame(['error'], array_column($response->json('data'), 'resultado'));
        $response->assertJsonPath('stats.total', 1);
    }

    public function test_logs_filtra_por_fecha_en_zona_local_con_limites_inclusivos(): void
    {
        $this->autenticar();
        $this->log(['mensaje_usuario' => 'dentro', 'created_at' => Carbon::parse('2026-11-09 05:59:00', 'UTC')]); // 08 23:59 local
        $this->log(['mensaje_usuario' => 'inicio', 'created_at' => Carbon::parse('2026-11-09 06:00:00', 'UTC')]); // 09 00:00 local
        $this->log(['mensaje_usuario' => 'fin', 'created_at' => Carbon::parse('2026-11-10 05:59:00', 'UTC')]);    // 09 23:59 local
        $this->log(['mensaje_usuario' => 'despues', 'created_at' => Carbon::parse('2026-11-10 06:00:00', 'UTC')]); // 10 00:00 local

        $mensajes = array_column(
            $this->getJson('/api/v1/asistente/benchmark/logs?fecha_inicio=2026-11-09&fecha_fin=2026-11-09')->assertOk()->json('data'),
            'mensaje_usuario'
        );

        sort($mensajes);
        $this->assertSame(['fin', 'inicio'], $mensajes);
    }

    public function test_logs_filtra_por_guard_sin_confundir_nombres_parecidos(): void
    {
        $this->autenticar();
        $this->log(['mensaje_usuario' => 'a', 'guards_nombres' => '|ToolLoopGuard|']);
        $this->log(['mensaje_usuario' => 'b', 'guards_nombres' => '|ToolLoopGuardExtra|']);
        $this->log(['mensaje_usuario' => 'c']);

        $mensajes = array_column($this->getJson('/api/v1/asistente/benchmark/logs?guard=ToolLoopGuard')->json('data'), 'mensaje_usuario');

        $this->assertSame(['a'], $mensajes);
    }

    public function test_logs_filtra_por_herramienta_y_search(): void
    {
        $this->autenticar();
        $this->log(['mensaje_usuario' => 'gastos de noviembre', 'respuesta_agente' => 'Total de gastos.'], [['herramienta' => 'consultar_gastos_periodo']]);
        $this->log(['mensaje_usuario' => 'aves en casita', 'respuesta_agente' => 'Hay 12 aves.'], [['herramienta' => 'consultar_inventario_aves']]);

        $porTool = $this->getJson('/api/v1/asistente/benchmark/logs?herramienta=consultar_gastos_periodo')->json('data');
        $porMensaje = $this->getJson('/api/v1/asistente/benchmark/logs?search=CASITA')->json('data');
        $porRespuesta = $this->getJson('/api/v1/asistente/benchmark/logs?search=hay 12')->json('data');

        $this->assertSame(['gastos de noviembre'], array_column($porTool, 'mensaje_usuario'));
        $this->assertSame(['aves en casita'], array_column($porMensaje, 'mensaje_usuario'));
        $this->assertSame(['aves en casita'], array_column($porRespuesta, 'mensaje_usuario'));
    }

    public function test_search_trata_los_comodines_como_texto_literal(): void
    {
        $this->autenticar();
        $this->log(['mensaje_usuario' => 'avance 100% completo']);
        $this->log(['mensaje_usuario' => 'otro mensaje']);

        $data = $this->getJson('/api/v1/asistente/benchmark/logs?search='.urlencode('%'))->json('data');

        $this->assertSame(['avance 100% completo'], array_column($data, 'mensaje_usuario'));
    }

    public function test_detalle_incluye_tools_guards_tokens_y_prompt(): void
    {
        $this->autenticar();
        $log = $this->log([
            'uuid_conversacion' => '7d9c7a0e-5c36-4a35-8a6b-1f5d6c1d2b11',
            'respuesta_original_llm' => 'texto original',
            'guards_aplicados' => [['guard' => 'DateFormatGuard', 'fase' => 'salida', 'accion' => 'modificacion', 'detalle' => 'x']],
            'iteraciones' => 2,
        ], [[
            'herramienta' => 'consultar_gastos_periodo',
            'argumentos' => ['mes' => 11],
            'tablas_consultadas' => ['mnt_gastos', 'ctl_categorias_gastos'],
            'resumen_resultado' => 'Datos obtenidos',
        ]]);

        $data = $this->getJson("/api/v1/asistente/benchmark/logs/{$log->id}")->assertOk()->json('data');

        $this->assertSame($log->id, $data['id']);
        $this->assertSame('¿cuántas aves hay?', $data['mensaje_usuario']);
        $this->assertSame('7d9c7a0e-5c36-4a35-8a6b-1f5d6c1d2b11', $data['uuid_conversacion']);
        $this->assertSame('Hay 12 aves.', $data['respuesta_agente']);
        $this->assertSame('texto original', $data['respuesta_original_llm']);
        $this->assertSame(['prompt' => 100, 'respuesta' => 20, 'total' => 120], $data['tokens']);
        $this->assertSame(2, $data['iteraciones']);
        $this->assertSame('DateFormatGuard', $data['guards_aplicados'][0]['guard']);
        $this->assertSame('consultar_gastos_periodo', $data['tools'][0]['herramienta']);
        $this->assertSame(['mes' => 11], $data['tools'][0]['argumentos']);
        $this->assertSame(['mnt_gastos', 'ctl_categorias_gastos'], $data['tools'][0]['tablas_consultadas']);
        $this->assertSame(PromptComposer::componer(), $data['prompt']['texto']);
        $this->assertSame(FuncionLogAgentService::versionPromptVigente(), $data['prompt']['version']);
        $this->assertTrue($data['prompt']['version_vigente']);
    }

    public function test_detalle_marca_prompt_desactualizado(): void
    {
        $this->autenticar();
        $log = $this->log(['prompt_version' => 'versionvieja1']);

        $data = $this->getJson("/api/v1/asistente/benchmark/logs/{$log->id}")->json('data');

        $this->assertSame('versionvieja1', $data['prompt']['version']);
        $this->assertFalse($data['prompt']['version_vigente']);
    }

    public function test_detalle_inexistente_retorna_404(): void
    {
        $this->autenticar();

        $this->getJson('/api/v1/asistente/benchmark/logs/99999')->assertStatus(404);
    }

    public function test_ninguna_respuesta_contiene_datos_de_usuario(): void
    {
        $usuario = $this->autenticar();
        $log = $this->log([], [['herramienta' => 'consultar_gastos_periodo', 'argumentos' => ['mes' => 11]]]);

        foreach (['estadisticas', 'logs', "logs/{$log->id}"] as $ruta) {
            $json = $this->getJson("/api/v1/asistente/benchmark/{$ruta}")->assertOk()->getContent();

            $this->assertStringNotContainsString($usuario->email, $json, $ruta);
            $this->assertStringNotContainsString('id_usuario', $json, $ruta);
            $this->assertStringNotContainsString('"email"', $json, $ruta);
        }
    }
}
