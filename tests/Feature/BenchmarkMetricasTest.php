<?php

namespace Tests\Feature;

use App\Models\FuncionLogAgent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Métricas de uso, calidad y rendimiento de la pantalla Estadísticas del Benchmark.
 * Un turno "consultó al modelo" si NO fue bloqueado por un guard ni resuelto por la vía rápida
 * (los errores del proveedor sí cuentan: llegaron al modelo y pueden haber gastado tokens).
 */
class BenchmarkMetricasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['avicola.timezone' => 'America/El_Salvador']);
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC'));
        Sanctum::actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function turno(string $resultado, int $prompt = 0, int $respuesta = 0, int $iteraciones = 0, int $ms = 100, array $atributos = []): FuncionLogAgent
    {
        return FuncionLogAgent::create(array_merge([
            'mensaje_usuario' => 'm',
            'turnos_historial' => 0,
            'resultado' => $resultado,
            'tokens_prompt' => $prompt,
            'tokens_respuesta' => $respuesta,
            'tokens_total' => $prompt + $respuesta,
            'iteraciones' => $iteraciones,
            'duracion_ms' => $ms,
            'created_at' => Carbon::parse('2026-11-10 12:00:00', 'UTC'),
        ], $atributos));
    }

    /**
     * @return array<string, mixed>
     */
    private function totales(string $query = 'dias=7'): array
    {
        return $this->getJson("/api/v1/asistente/benchmark/estadisticas?{$query}")->assertOk()->json('data.totales');
    }

    public function test_sin_datos_todas_las_metricas_nuevas_son_cero_sin_dividir_por_cero(): void
    {
        $t = $this->totales();

        foreach ([
            'con_modelo', 'sin_modelo', 'tokens_prompt', 'tokens_respuesta',
            'tokens_por_consulta', 'llamadas_por_consulta', 'resueltas',
            'latencia_mediana_ms', 'latencia_p90_ms', 'espera_errores_ms',
        ] as $clave) {
            $this->assertArrayHasKey($clave, $t, "Falta {$clave}");
            $this->assertSame(0, $t[$clave], "{$clave} debería ser 0");
        }
        foreach (['pct_resueltas', 'pct_bloqueadas', 'pct_errores'] as $clave) {
            $this->assertSame(0, $t[$clave], "{$clave} debería ser 0");
        }
    }

    public function test_separa_turnos_con_modelo_y_sin_modelo_por_resultado_y_no_por_tokens(): void
    {
        $this->turno('exitoso', 100, 20, 2);
        $this->turno('exitoso', 50, 10, 1);
        $this->turno('fast_path');
        $this->turno('bloqueado_guard');
        $this->turno('error', 0, 0, 0, 14000);          // error sin tokens: igual llegó al modelo
        $this->turno('error', 2000, 40, 1, 72000);      // error con tokens

        $t = $this->totales();

        $this->assertSame(6, $t['interacciones']);
        $this->assertSame(4, $t['con_modelo']);          // 2 exitosos + 2 errores
        $this->assertSame(2, $t['sin_modelo']);          // fast_path + bloqueo
    }

    public function test_desglose_de_tokens_en_entrada_y_salida_y_promedio_por_consulta_al_modelo(): void
    {
        $this->turno('exitoso', 100, 20, 2);
        $this->turno('exitoso', 50, 10, 1);
        $this->turno('fast_path');                       // no entra en el promedio por consulta
        $this->turno('bloqueado_guard');

        $t = $this->totales();

        $this->assertSame(150, $t['tokens_prompt']);
        $this->assertSame(30, $t['tokens_respuesta']);
        $this->assertSame(180, $t['tokens_total']);
        $this->assertSame(90, $t['tokens_por_consulta']);      // 180 / 2 consultas al modelo
        $this->assertSame(45, $t['tokens_promedio']);          // compatibilidad: 180 / 4 turnos
        $this->assertSame(1.5, (float) $t['llamadas_por_consulta']); // (2 + 1) / 2
    }

    public function test_resueltas_bloqueadas_y_errores_con_porcentajes_sobre_el_total(): void
    {
        $this->turno('exitoso', 100, 20, 1);
        $this->turno('exitoso', 100, 20, 1);
        $this->turno('fast_path');
        $this->turno('bloqueado_guard');
        $this->turno('error', 0, 0, 0, 1000);

        $t = $this->totales();

        $this->assertSame(3, $t['resueltas']);                 // 2 exitosos + 1 fast_path
        $this->assertSame(1, $t['bloqueadas']);
        $this->assertSame(1, $t['errores']);
        $this->assertSame(60.0, (float) $t['pct_resueltas']);
        $this->assertSame(20.0, (float) $t['pct_bloqueadas']);
        $this->assertSame(20.0, (float) $t['pct_errores']);
        // Compatibilidad con la API anterior
        $this->assertSame(3, $t['exitosas']);
        $this->assertSame(60.0, (float) $t['tasa_exito']);
    }

    public function test_espera_acumulada_de_errores_suma_solo_la_duracion_de_los_errores(): void
    {
        $this->turno('error', 0, 0, 0, 14808);
        $this->turno('error', 2289, 0, 1, 72680);
        $this->turno('exitoso', 100, 20, 1, 9000);             // no cuenta

        $this->assertSame(87488, $this->totales()['espera_errores_ms']);
    }

    public function test_latencia_mediana_y_p90_solo_sobre_turnos_que_consultaron_al_modelo(): void
    {
        // Con modelo: 10 turnos de 1 a 10 segundos
        foreach (range(1, 10) as $s) {
            $this->turno('exitoso', 100, 10, 1, $s * 1000);
        }
        // Sin modelo: muy rápidos, no deben bajar la mediana
        $this->turno('bloqueado_guard', 0, 0, 0, 100);
        $this->turno('fast_path', 0, 0, 0, 100);
        $this->turno('bloqueado_guard', 0, 0, 0, 100);

        $t = $this->totales();

        $this->assertSame(5500, $t['latencia_mediana_ms']);    // mediana de 1..10 s (promedio de 5 s y 6 s)
        $this->assertSame(9000, $t['latencia_p90_ms']);        // percentil 90 por rango más cercano: 9.º valor
    }

    public function test_latencia_con_un_solo_turno_con_modelo(): void
    {
        $this->turno('exitoso', 100, 10, 1, 8000);

        $t = $this->totales();

        $this->assertSame(8000, $t['latencia_mediana_ms']);
        $this->assertSame(8000, $t['latencia_p90_ms']);
    }

    public function test_los_campos_anteriores_de_totales_se_conservan(): void
    {
        $this->turno('exitoso', 100, 20, 1, 400);

        $t = $this->totales();

        foreach (['interacciones', 'exitosas', 'fast_path', 'bloqueadas', 'errores', 'tasa_exito', 'tokens_total', 'tokens_promedio', 'latencia_promedio_ms'] as $clave) {
            $this->assertArrayHasKey($clave, $t, "Se perdió el campo {$clave}");
        }
    }

    public function test_las_metricas_respetan_el_rango_de_dias(): void
    {
        $this->turno('exitoso', 100, 20, 1);
        $this->turno('exitoso', 900, 90, 1, 100, ['created_at' => Carbon::parse('2026-10-20 12:00:00', 'UTC')]); // fuera de 7 días

        $siete = $this->totales('dias=7');
        $treinta = $this->totales('dias=30');

        $this->assertSame(1, $siete['con_modelo']);
        $this->assertSame(120, $siete['tokens_total']);
        $this->assertSame(2, $treinta['con_modelo']);
        $this->assertSame(1110, $treinta['tokens_total']);
    }
}
