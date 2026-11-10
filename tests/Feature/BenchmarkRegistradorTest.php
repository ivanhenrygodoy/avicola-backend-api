<?php

namespace Tests\Feature;

use App\Models\FuncionLogAgent;
use App\Services\Asistente\Benchmark\FuncionLogAgentService;
use App\Services\Asistente\Benchmark\RastroTurno;
use App\Services\Asistente\PromptComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BenchmarkRegistradorTest extends TestCase
{
    use RefreshDatabase;

    private function rastro(string $resultado = FuncionLogAgent::RESULTADO_EXITOSO): RastroTurno
    {
        $r = new RastroTurno('¿cuántos gastos hubo?', 2, '7d9c7a0e-5c36-4a35-8a6b-1f5d6c1d2b11');
        $r->resultado = $resultado;
        $r->respuestaAgente = 'Hubo 3 gastos.';

        return $r;
    }

    public function test_registra_turno_con_tools_guards_y_tokens(): void
    {
        config(['gemini.model' => 'gemini-test']);
        $r = $this->rastro();
        $r->agregarTokens(['usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20]]);
        $r->agregarTokens(['usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 10]]);
        $r->iteraciones = 2;
        $r->agregarEventos([['guard' => 'DateFormatGuard', 'fase' => 'salida', 'accion' => 'modificacion', 'detalle' => 'x']]);
        $r->agregarEventos([['guard' => 'LengthAndBrevityGuard', 'fase' => 'salida', 'accion' => 'aviso', 'detalle' => 'y']]);
        $r->tools[] = [
            'herramienta' => 'consultar_gastos_periodo',
            'argumentos' => ['mes' => 11],
            'tablas' => ['mnt_gastos', 'ctl_categorias_gastos'],
            'resumen' => 'Datos obtenidos',
            'exito' => true,
            'duracion_ms' => 12,
        ];

        $log = app(FuncionLogAgentService::class)->registrar($r);

        $this->assertNotNull($log);
        $guardado = FuncionLogAgent::with('tools')->findOrFail($log->id);
        $this->assertSame(150, $guardado->tokens_prompt);
        $this->assertSame(30, $guardado->tokens_respuesta);
        $this->assertSame(180, $guardado->tokens_total);
        $this->assertSame(2, $guardado->iteraciones);
        $this->assertSame('gemini-test', $guardado->modelo);
        $this->assertSame('|DateFormatGuard|LengthAndBrevityGuard|', $guardado->guards_nombres);
        $this->assertCount(2, $guardado->guards_aplicados);
        $this->assertSame('7d9c7a0e-5c36-4a35-8a6b-1f5d6c1d2b11', $guardado->uuid_conversacion);
        $this->assertSame(2, $guardado->turnos_historial);
        $this->assertCount(1, $guardado->tools);
        $this->assertSame(['mnt_gastos', 'ctl_categorias_gastos'], $guardado->tools->first()->tablas_consultadas);
        $this->assertSame(1, $guardado->tools->first()->orden);
    }

    public function test_sin_usage_metadata_los_tokens_son_cero(): void
    {
        $r = $this->rastro(FuncionLogAgent::RESULTADO_BLOQUEADO);
        $r->agregarTokens([]);

        $log = app(FuncionLogAgentService::class)->registrar($r);

        $this->assertSame(0, $log->tokens_total);
        $this->assertNull($log->guards_nombres);
    }

    public function test_argumentos_se_guardan_sin_claves_de_identidad_incluso_anidadas(): void
    {
        $r = $this->rastro();
        $r->tools[] = [
            'herramienta' => 'consultar_gastos_periodo',
            'argumentos' => ['id_usuario' => 5, 'mes' => 11, 'filtro' => ['email' => 'a@b.c', 'categoria' => 'Alimento']],
            'tablas' => [],
            'resumen' => '',
            'exito' => true,
            'duracion_ms' => 1,
        ];

        $log = app(FuncionLogAgentService::class)->registrar($r);

        $this->assertSame(['mes' => 11, 'filtro' => ['categoria' => 'Alimento']], $log->tools->first()->argumentos);
    }

    public function test_error_se_guarda_saneado_sin_api_key_ni_traza(): void
    {
        $r = $this->rastro(FuncionLogAgent::RESULTADO_ERROR);
        $r->error = 'Error en Gemini (500): https://g.googleapis.com/v1/m:generate?key=AIzaSECRET123 en app/Services/Asistente/GeminiApiClient.php';

        $log = app(FuncionLogAgentService::class)->registrar($r);

        $this->assertStringNotContainsString('AIzaSECRET123', (string) $log->error_mensaje);
        $this->assertStringNotContainsString('app/Services', (string) $log->error_mensaje);
    }

    public function test_error_y_resumen_se_truncan(): void
    {
        $r = $this->rastro(FuncionLogAgent::RESULTADO_ERROR);
        $r->error = str_repeat('e', 900);
        $r->tools[] = ['herramienta' => 't', 'argumentos' => [], 'tablas' => [], 'resumen' => str_repeat('r', 900), 'exito' => false, 'duracion_ms' => 1];

        $log = app(FuncionLogAgentService::class)->registrar($r);

        $this->assertSame(300, mb_strlen((string) $log->error_mensaje));
        $this->assertSame(300, mb_strlen((string) $log->tools->first()->resumen_resultado));
    }

    public function test_registrar_no_lanza_si_la_bd_falla_y_deja_rastro_en_el_log(): void
    {
        Log::spy();
        Schema::drop('mnt_funcion_log_agent_tools');
        Schema::drop('mnt_funcion_log_agent');

        $resultado = app(FuncionLogAgentService::class)->registrar($this->rastro());

        $this->assertNull($resultado);
        Log::shouldHaveReceived('error')->atLeast()->once();
    }

    public function test_prompt_version_es_el_hash_corto_del_prompt_vigente(): void
    {
        $log = app(FuncionLogAgentService::class)->registrar($this->rastro());

        $this->assertSame(substr(sha1(PromptComposer::componer()), 0, 12), $log->prompt_version);
        $this->assertSame(FuncionLogAgentService::versionPromptVigente(), $log->prompt_version);
    }

    public function test_guarda_respuesta_original_del_llm_cuando_un_guard_la_reemplazo(): void
    {
        $r = $this->rastro(FuncionLogAgent::RESULTADO_BLOQUEADO);
        $r->respuestaOriginalLlm = 'falló en mnt_gastos';
        $r->respuestaAgente = 'No pude completar esa respuesta de forma segura.';

        $log = app(FuncionLogAgentService::class)->registrar($r);

        $this->assertSame('falló en mnt_gastos', $log->respuesta_original_llm);
    }
}
