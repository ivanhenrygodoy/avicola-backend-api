<?php

namespace Tests\Feature;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\Contracts\GuardrailInterface;
use App\Services\Asistente\Guardrails\GuardrailPipeline;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;
use Tests\TestCase;

class GuardrailsPipelineTest extends TestCase
{
    /**
     * Guard de prueba configurable: devuelve siempre el resultado indicado y cuenta sus ejecuciones.
     */
    private function guard(string $nombre, string $fase, ResultadoGuardrail $resultado): object
    {
        return new class($nombre, $fase, $resultado) implements GuardrailInterface
        {
            public int $ejecuciones = 0;

            public function __construct(private string $nombre, private string $fase, private ResultadoGuardrail $resultado) {}

            public function nombre(): string
            {
                return $this->nombre;
            }

            public function fase(): string
            {
                return $this->fase;
            }

            public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
            {
                $this->ejecuciones++;

                return $this->resultado;
            }
        };
    }

    public function test_entrada_se_detiene_en_el_primer_bloqueo_y_no_ejecuta_los_siguientes(): void
    {
        $primero = $this->guard('Primero', 'entrada', ResultadoGuardrail::bloquea('Bloqueado.', 'motivo'));
        $segundo = $this->guard('Segundo', 'entrada', ResultadoGuardrail::bloquea('Otro.'));

        $resultado = (new GuardrailPipeline([$primero, $segundo]))->evaluarEntrada('hola');

        $this->assertTrue($resultado->detenido);
        $this->assertFalse($resultado->fastPath);
        $this->assertSame('Bloqueado.', $resultado->texto);
        $this->assertSame(1, $primero->ejecuciones);
        $this->assertSame(0, $segundo->ejecuciones);
        $this->assertSame(
            [['guard' => 'Primero', 'fase' => 'entrada', 'accion' => 'bloqueo', 'detalle' => 'motivo']],
            $resultado->eventos
        );
    }

    public function test_fast_path_detiene_y_se_marca_como_tal(): void
    {
        $resultado = (new GuardrailPipeline([
            $this->guard('Saludo', 'entrada', ResultadoGuardrail::fastPath('¡Hola!')),
        ]))->evaluarEntrada('hola');

        $this->assertTrue($resultado->detenido);
        $this->assertTrue($resultado->fastPath);
        $this->assertSame('¡Hola!', $resultado->texto);
    }

    public function test_avisos_se_acumulan_sin_detener(): void
    {
        $a = $this->guard('A', 'entrada', ResultadoGuardrail::avisa('uno'));
        $b = $this->guard('B', 'entrada', ResultadoGuardrail::avisa('dos'));

        $resultado = (new GuardrailPipeline([$a, $b]))->evaluarEntrada('texto');

        $this->assertFalse($resultado->detenido);
        $this->assertNull($resultado->texto);
        $this->assertSame(['A', 'B'], array_column($resultado->eventos, 'guard'));
        $this->assertSame(['aviso', 'aviso'], array_column($resultado->eventos, 'accion'));
    }

    public function test_eventos_omiten_guards_que_pasan_y_se_filtran_por_fase(): void
    {
        $pasa = $this->guard('Pasa', 'entrada', ResultadoGuardrail::pasa());
        $deSalida = $this->guard('DeSalida', 'salida', ResultadoGuardrail::bloquea('x'));

        $resultado = (new GuardrailPipeline([$pasa, $deSalida]))->evaluarEntrada('texto');

        $this->assertFalse($resultado->detenido);
        $this->assertSame([], $resultado->eventos);
        $this->assertSame(1, $pasa->ejecuciones);
        $this->assertSame(0, $deSalida->ejecuciones);
    }

    public function test_salida_encadena_modificaciones_en_orden(): void
    {
        $uno = new class implements GuardrailInterface
        {
            public function nombre(): string
            {
                return 'Uno';
            }

            public function fase(): string
            {
                return 'salida';
            }

            public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
            {
                return ResultadoGuardrail::modifica($ctx->respuesta.' +uno', 'agrega uno');
            }
        };
        $dos = new class implements GuardrailInterface
        {
            public function nombre(): string
            {
                return 'Dos';
            }

            public function fase(): string
            {
                return 'salida';
            }

            public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
            {
                return ResultadoGuardrail::modifica($ctx->respuesta.' +dos');
            }
        };

        $resultado = (new GuardrailPipeline([$uno, $dos]))->evaluarSalida('base', []);

        $this->assertFalse($resultado->detenido);
        $this->assertSame('base +uno +dos', $resultado->texto);
        $this->assertSame(['modificacion', 'modificacion'], array_column($resultado->eventos, 'accion'));
    }

    public function test_salida_sin_cambios_devuelve_el_texto_original(): void
    {
        $resultado = (new GuardrailPipeline([
            $this->guard('Pasa', 'salida', ResultadoGuardrail::pasa()),
        ]))->evaluarSalida('texto original', []);

        $this->assertSame('texto original', $resultado->texto);
        $this->assertFalse($resultado->detenido);
    }

    public function test_bloqueo_en_salida_reemplaza_la_respuesta_y_corta(): void
    {
        $bloquea = $this->guard('Fuga', 'salida', ResultadoGuardrail::bloquea('Mensaje seguro.', 'tabla expuesta'));
        $despues = $this->guard('Despues', 'salida', ResultadoGuardrail::modifica('no debe verse'));

        $resultado = (new GuardrailPipeline([$bloquea, $despues]))->evaluarSalida('falló en mnt_gastos', []);

        $this->assertTrue($resultado->detenido);
        $this->assertSame('Mensaje seguro.', $resultado->texto);
        $this->assertSame(0, $despues->ejecuciones);
    }

    public function test_tool_y_bucle_usan_sus_propias_fases(): void
    {
        $tool = $this->guard('ValidaArgs', 'tool', ResultadoGuardrail::bloquea('Fecha inválida.'));
        $bucle = $this->guard('Limite', 'bucle', ResultadoGuardrail::bloquea('Límite alcanzado.'));
        $pipeline = new GuardrailPipeline([$tool, $bucle]);

        $rTool = $pipeline->evaluarTool('consultar_gastos_periodo', ['fecha_inicio' => 'x']);
        $rBucle = $pipeline->evaluarBucle(3);

        $this->assertTrue($rTool->detenido);
        $this->assertSame('Fecha inválida.', $rTool->texto);
        $this->assertSame('tool', $rTool->eventos[0]['fase']);
        $this->assertTrue($rBucle->detenido);
        $this->assertSame('Límite alcanzado.', $rBucle->texto);
        $this->assertSame('bucle', $rBucle->eventos[0]['fase']);
    }

    public function test_el_contenedor_resuelve_el_pipeline_con_los_guards_etiquetados(): void
    {
        $pipeline = $this->app->make(GuardrailPipeline::class);

        $this->assertInstanceOf(GuardrailPipeline::class, $pipeline);
        $this->assertSame(
            count(iterator_to_array($this->app->tagged('asistente.guardrails'))),
            $pipeline->total()
        );
    }

    public function test_config_define_los_mensajes_estandar(): void
    {
        foreach (['alcance', 'profesionalismo', 'persona', 'injection', 'matematica', 'fuga', 'limite_tools'] as $clave) {
            $this->assertNotEmpty(config("guardrails.mensajes.{$clave}"), "Falta el mensaje [{$clave}]");
        }
        $this->assertStringContainsString('límite de consultas', config('guardrails.mensajes.limite_tools'));
    }
}
