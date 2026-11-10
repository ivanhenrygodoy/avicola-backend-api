<?php

namespace App\Services\Asistente\Guardrails\Entrada;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Evita que el usuario cambie el tono o la identidad de AviaCore (pirata, rima, sarcasmo…).
 */
final class PersonaDriftGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_ENTRADA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $texto = $this->normalizar($ctx->mensaje);

        if ($this->coincideAlguno($this->patrones('persona'), $texto)) {
            return ResultadoGuardrail::bloquea(
                $this->mensaje('persona'),
                'Intento de cambiar el tono o la identidad del asistente.'
            );
        }

        return ResultadoGuardrail::pasa();
    }
}
