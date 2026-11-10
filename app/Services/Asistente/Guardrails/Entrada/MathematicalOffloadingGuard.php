<?php

namespace App\Services\Asistente\Guardrails\Entrada;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Impide usar al asistente como calculadora. Bloquea aritmética o álgebra pura; si el mensaje
 * menciona vocabulario de la granja ("suma los gastos de la semana") pasa al modelo.
 */
final class MathematicalOffloadingGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_ENTRADA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $texto = $this->normalizar($ctx->mensaje);

        $esMatematica = $this->coincideAlguno($this->patrones('aritmetica_expresion'), $this->sinFechas($texto))
            || $this->coincideAlguno($this->patrones('aritmetica_verbo'), $texto);

        if ($esMatematica && ! $this->mencionaVocabularioGranja($texto)) {
            return ResultadoGuardrail::bloquea(
                $this->mensaje('matematica'),
                'Cálculo aritmético o algebraico ajeno al dominio de la granja.'
            );
        }

        return ResultadoGuardrail::pasa();
    }
}
