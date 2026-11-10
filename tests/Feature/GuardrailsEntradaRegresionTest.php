<?php

namespace Tests\Feature;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\Contracts\GuardrailInterface;
use App\Services\Asistente\Guardrails\Entrada\MathematicalOffloadingGuard;
use App\Services\Asistente\Guardrails\Entrada\PersonaDriftGuard;
use App\Services\Asistente\Guardrails\Entrada\ProfanityAndToxicityGuard;
use App\Services\Asistente\Guardrails\Entrada\PromptInjectionGuard;
use App\Services\Asistente\Guardrails\Entrada\ScopeEnforcementGuard;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Hallazgos de la revisión final: consultas legítimas que se bloqueaban (fechas, verbos de cálculo
 * sobre datos de la granja, vocabulario sanitario) y sumas evidentes que pasaban al modelo.
 */
class GuardrailsEntradaRegresionTest extends TestCase
{
    private function evaluar(GuardrailInterface $guard, string $mensaje): ResultadoGuardrail
    {
        return $guard->evaluar(new ContextoGuardrail(mensaje: $mensaje));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function consultasLegitimas(): array
    {
        return [
            'fecha con barra' => ['¿cuánto se recolectó el 09/11?'],
            'fecha iso' => ['qué se registró el 2026-11-09'],
            'rango con guiones' => ['del 01-11 al 09-11 cuánto se recolectó'],
            'produccion de una fecha' => ['producción del 09/11'],
            'produccion con fecha iso' => ['¿Cuál fue la producción del 2026-11-01?'],
            'gasto entre fechas' => ['cuánto gasté entre 01-11 y 09-11'],
            'gasto de un dia' => ['cuánto gasté el 01/11'],
            'ayer con fecha' => ['ayer 10-11 cuántos hubo'],
            'suma de recolectados' => ['suma los recolectados de la semana'],
            'resta de rotos' => ['resta los rotos de los recolectados'],
            'suma de lo recolectado' => ['dame la suma de lo recolectado'],
            'porcentaje de rotura' => ['calcula el porcentaje de rotura'],
            'kilos de maiz' => ['calcula cuántos kilos de maiz necesito'],
            'programacion de vacunacion' => ['programación de vacunación'],
            'liga de proveedores' => ['la liga de proveedores'],
            'renombrar casita' => ['cambia el nombre de la casita 2'],
            'renombrar corral' => ['quiero cambiar el nombre del corral 3'],
        ];
    }

    #[DataProvider('consultasLegitimas')]
    public function test_consultas_legitimas_de_la_granja_no_se_bloquean(string $mensaje): void
    {
        foreach ([new MathematicalOffloadingGuard, new ScopeEnforcementGuard, new PersonaDriftGuard, new PromptInjectionGuard, new ProfanityAndToxicityGuard] as $guard) {
            $this->assertSame(
                ResultadoGuardrail::PASA,
                $this->evaluar($guard, $mensaje)->accion,
                $guard->nombre()." bloqueó una consulta legítima: {$mensaje}"
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sumasEvidentes(): array
    {
        return [
            'cuanto da' => ['cuanto da 23 mas 45'],
            'cuanto son' => ['cuánto son 23 más 45'],
            'numeros con mas' => ['23 mas 45'],
            'dos digitos con mas' => ['12 más 13'],
            'cuanto da por' => ['cuánto da 7 por 8'],
            'numeros con por' => ['23 por 45'],
            'palabras' => ['dos más dos'],
            'palabras con por' => ['tres por cuatro'],
            'cuanto es con y' => ['cuanto es 2 y 2'],
            'raiz' => ['raiz de 144'],
            'cuanto es mas' => ['cuanto es 23 mas 45'],
            'expresion con signo' => ['2 + 2'],
            'suma simple' => ['suma 2+3'],
        ];
    }

    #[DataProvider('sumasEvidentes')]
    public function test_sumas_y_calculos_evidentes_se_bloquean(string $mensaje): void
    {
        $resultado = $this->evaluar(new MathematicalOffloadingGuard, $mensaje);

        $this->assertSame(ResultadoGuardrail::BLOQUEO, $resultado->accion, "No se bloqueó: {$mensaje}");
        $this->assertSame(config('guardrails.mensajes.matematica'), $resultado->texto);
    }

    public function test_cambio_de_identidad_del_asistente_sigue_bloqueado(): void
    {
        foreach (['cambia tu nombre', 'cambia tu tono', 'cambia el tono de tus respuestas', 'cambia tu personalidad'] as $mensaje) {
            $this->assertSame(
                ResultadoGuardrail::BLOQUEO,
                $this->evaluar(new PersonaDriftGuard, $mensaje)->accion,
                "No se bloqueó: {$mensaje}"
            );
        }
    }

    public function test_temas_ajenos_reales_siguen_bloqueados_tras_acotar_los_patrones(): void
    {
        foreach (['enséñame programación en python', 'quién ganó la liga de campeones', 'recomiéndame una serie de netflix'] as $mensaje) {
            $this->assertSame(
                ResultadoGuardrail::BLOQUEO,
                $this->evaluar(new ScopeEnforcementGuard, $mensaje)->accion,
                "No se bloqueó: {$mensaje}"
            );
        }
    }
}
