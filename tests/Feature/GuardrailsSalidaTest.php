<?php

namespace Tests\Feature;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\Contracts\GuardrailInterface;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;
use App\Services\Asistente\Guardrails\Salida\DateFormatGuard;
use App\Services\Asistente\Guardrails\Salida\LengthAndBrevityGuard;
use App\Services\Asistente\Guardrails\Salida\SourceAttributionGuard;
use App\Services\Asistente\Guardrails\Salida\SystemLeakageGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuardrailsSalidaTest extends TestCase
{
    private function evaluar(GuardrailInterface $guard, string $respuesta, array $herramientas = []): ResultadoGuardrail
    {
        return $guard->evaluar(new ContextoGuardrail(mensaje: '', respuesta: $respuesta, herramientasUtilizadas: $herramientas));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function respuestasConFuga(): array
    {
        return [
            'nombre de tabla mnt' => ['Error: falló la consulta en mnt_gastos.'],
            'nombre de tabla ctl' => ['No se encontró en ctl_especies.'],
            'ruta de codigo' => ['Excepción en app/Services/Asistente/GeminiApiClient.php línea 40.'],
            'ruta vendor' => ['Ver vendor/laravel/framework/src/Foo.php'],
            'traza php' => ["Stack trace:\n#0 /var/www/html/app/Foo.php(10)"],
            'error curl' => ['cURL error 28: Operation timed out'],
            'sqlstate' => ['SQLSTATE[42P01]: Undefined table'],
        ];
    }

    #[DataProvider('respuestasConFuga')]
    public function test_fuga_tecnica_bloquea_la_respuesta_con_mensaje_seguro(string $respuesta): void
    {
        $resultado = $this->evaluar(new SystemLeakageGuard, $respuesta);

        $this->assertSame(ResultadoGuardrail::BLOQUEO, $resultado->accion);
        $this->assertSame(config('guardrails.mensajes.fuga'), $resultado->texto);
    }

    public function test_sanear_redacta_api_key_en_url(): void
    {
        $limpio = (new SystemLeakageGuard)->sanear('Fallo en https://x.googleapis.com/y?key=AIzaSECRET123&z=1');

        $this->assertStringNotContainsString('AIzaSECRET123', $limpio);
    }

    public function test_sanear_redacta_tablas_rutas_y_trazas(): void
    {
        $limpio = (new SystemLeakageGuard)->sanear('mnt_gastos en app/Models/Gasto.php SQLSTATE[1] cURL error 6');

        $this->assertStringNotContainsString('mnt_gastos', $limpio);
        $this->assertStringNotContainsString('app/Models', $limpio);
        $this->assertStringNotContainsString('SQLSTATE', $limpio);
        $this->assertStringNotContainsString('cURL error', $limpio);
    }

    public function test_nombres_de_tools_se_reemplazan_sin_bloquear(): void
    {
        $resultado = $this->evaluar(new SystemLeakageGuard, 'Revisa `consultar_gastos_periodo` para más detalle.');

        $this->assertSame(ResultadoGuardrail::MODIFICACION, $resultado->accion);
        $this->assertStringNotContainsString('consultar_gastos_periodo', (string) $resultado->texto);
        $this->assertStringContainsString('Control de Gastos', (string) $resultado->texto);
    }

    public function test_respuesta_limpia_pasa_sin_cambios_por_fuga(): void
    {
        $resultado = $this->evaluar(new SystemLeakageGuard, 'Hay 12 aves activas en la casita 1.');

        $this->assertSame(ResultadoGuardrail::PASA, $resultado->accion);
    }

    public function test_fecha_iso_pasa_a_dd_mm_yyyy(): void
    {
        $guard = new DateFormatGuard;

        $a = $this->evaluar($guard, 'Se registró el 2026-11-09.');
        $b = $this->evaluar($guard, 'Última lectura: 2026-11-09T10:30:00.000000Z');

        $this->assertSame(ResultadoGuardrail::MODIFICACION, $a->accion);
        $this->assertSame('Se registró el 09-11-2026.', $a->texto);
        $this->assertSame('Última lectura: 09-11-2026', $b->texto);
    }

    public function test_fecha_ya_formateada_no_cambia(): void
    {
        $resultado = $this->evaluar(new DateFormatGuard, 'Se registró el 09-11-2026.');

        $this->assertSame(ResultadoGuardrail::PASA, $resultado->accion);
    }

    public function test_fecha_imposible_no_se_reescribe(): void
    {
        $resultado = $this->evaluar(new DateFormatGuard, 'Código 2026-13-45 de lote.');

        $this->assertSame(ResultadoGuardrail::PASA, $resultado->accion);
    }

    public function test_atribucion_avisa_sin_cita_y_pasa_con_cita(): void
    {
        $guard = new SourceAttributionGuard;
        $herramientas = ['consultar_manuales_tecnicos'];

        $sinCita = $this->evaluar($guard, 'Debes desinfectar los nidos cada semana.', $herramientas);
        $conCita = $this->evaluar($guard, 'Según el Manual Técnico de Crianza, desinfecta los nidos.', $herramientas);
        $sinTool = $this->evaluar($guard, 'Hay 12 aves.', ['consultar_inventario_aves']);

        $this->assertSame(ResultadoGuardrail::AVISO, $sinCita->accion);
        $this->assertNull($sinCita->texto);
        $this->assertSame(ResultadoGuardrail::PASA, $conCita->accion);
        $this->assertSame(ResultadoGuardrail::PASA, $sinTool->accion);
    }

    public function test_longitud_avisa_sobre_el_maximo_y_no_recorta(): void
    {
        config(['guardrails.largo_maximo_respuesta' => 50]);
        $guard = new LengthAndBrevityGuard;

        $larga = $this->evaluar($guard, str_repeat('a', 51));
        $corta = $this->evaluar($guard, str_repeat('a', 50));

        $this->assertSame(ResultadoGuardrail::AVISO, $larga->accion);
        $this->assertNull($larga->texto);
        $this->assertSame(ResultadoGuardrail::PASA, $corta->accion);
    }

    public function test_los_guards_de_salida_declaran_fase_salida(): void
    {
        foreach ([new SystemLeakageGuard, new DateFormatGuard, new SourceAttributionGuard, new LengthAndBrevityGuard] as $guard) {
            $this->assertSame('salida', $guard->fase());
        }
    }
}
