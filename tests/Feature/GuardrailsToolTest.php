<?php

namespace Tests\Feature;

use App\Services\Asistente\AsistenteToolRegistry;
use App\Services\Asistente\Guardrails\Bucle\ToolLoopGuard;
use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;
use App\Services\Asistente\Guardrails\Tool\ToolArgumentValidationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class GuardrailsToolTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $argumentos): ResultadoGuardrail
    {
        return (new ToolArgumentValidationGuard)->evaluar(
            new ContextoGuardrail(mensaje: '', herramienta: 'consultar_gastos_periodo', argumentos: $argumentos)
        );
    }

    public function test_tool_guard_rechaza_fecha_con_formato_invalido(): void
    {
        $resultado = $this->validar(['fecha_inicio' => '09/11/2026']);

        $this->assertSame(ResultadoGuardrail::BLOQUEO, $resultado->accion);
        $this->assertStringContainsString('fecha_inicio', (string) $resultado->texto);
    }

    public function test_tool_guard_rechaza_fecha_inexistente(): void
    {
        $this->assertSame(ResultadoGuardrail::BLOQUEO, $this->validar(['fecha_fin' => '2026-02-30'])->accion);
    }

    public function test_tool_guard_rechaza_fecha_fin_menor_que_inicio(): void
    {
        $resultado = $this->validar(['fecha_inicio' => '2026-11-10', 'fecha_fin' => '2026-11-01']);

        $this->assertSame(ResultadoGuardrail::BLOQUEO, $resultado->accion);
        $this->assertStringContainsString('anterior', (string) $resultado->texto);
    }

    public function test_tool_guard_valida_tambien_los_alias_desde_y_hasta(): void
    {
        $resultado = $this->validar(['fecha_desde' => '2026-11-10', 'fecha_hasta' => '2026-11-01']);

        $this->assertSame(ResultadoGuardrail::BLOQUEO, $resultado->accion);
    }

    public function test_tool_guard_rechaza_especie_no_permitida(): void
    {
        $resultado = $this->validar(['especie' => 'Ganso']);

        $this->assertSame(ResultadoGuardrail::BLOQUEO, $resultado->accion);
        $this->assertStringContainsString('Gallina', (string) $resultado->texto);
    }

    public function test_tool_guard_acepta_especie_sin_importar_mayusculas(): void
    {
        $this->assertSame(ResultadoGuardrail::PASA, $this->validar(['especie' => 'chompipe'])->accion);
        $this->assertSame(ResultadoGuardrail::PASA, $this->validar(['especie' => 'PATO'])->accion);
    }

    public function test_tool_guard_acepta_argumentos_validos_vacios_y_mismo_dia(): void
    {
        $this->assertSame(ResultadoGuardrail::PASA, $this->validar([])->accion);
        $this->assertSame(ResultadoGuardrail::PASA, $this->validar(['fecha_inicio' => '2026-11-01', 'fecha_fin' => '2026-11-10'])->accion);
        $this->assertSame(ResultadoGuardrail::PASA, $this->validar(['fecha_inicio' => '2026-11-10', 'fecha_fin' => '2026-11-10'])->accion);
        $this->assertSame(ResultadoGuardrail::PASA, $this->validar(['fecha_inicio' => '', 'especie' => ''])->accion);
    }

    public function test_tool_guard_ignora_argumentos_que_no_son_texto(): void
    {
        $this->assertSame(ResultadoGuardrail::BLOQUEO, $this->validar(['fecha_inicio' => ['2026-11-01']])->accion);
    }

    public function test_loop_guard_bloquea_en_la_iteracion_limite_y_no_antes(): void
    {
        $guard = new ToolLoopGuard;

        $this->assertSame(3, ToolLoopGuard::LIMITE);
        $this->assertSame('bucle', $guard->fase());
        $this->assertSame(ResultadoGuardrail::PASA, $guard->evaluar(new ContextoGuardrail(mensaje: '', iteracion: 2))->accion);

        $bloqueo = $guard->evaluar(new ContextoGuardrail(mensaje: '', iteracion: 3));
        $this->assertSame(ResultadoGuardrail::BLOQUEO, $bloqueo->accion);
        $this->assertStringContainsString('límite de consultas', (string) $bloqueo->texto);
    }

    public function test_cada_tool_declara_tablas_que_existen_en_la_base(): void
    {
        $registry = $this->app->make(AsistenteToolRegistry::class);
        $declaraciones = $registry->obtenerDeclaracionesTools();

        $this->assertNotEmpty($declaraciones);

        foreach ($declaraciones as $declaracion) {
            $tablas = $registry->obtener($declaracion['name'])->tablasConsultadas();

            $this->assertNotEmpty($tablas, "{$declaracion['name']} no declara tablas");
            foreach ($tablas as $tabla) {
                $this->assertMatchesRegularExpression('/^(mnt|ctl)_/', $tabla);
                $this->assertTrue(Schema::hasTable($tabla), "{$declaracion['name']} declara una tabla inexistente: {$tabla}");
            }
        }
    }
}
