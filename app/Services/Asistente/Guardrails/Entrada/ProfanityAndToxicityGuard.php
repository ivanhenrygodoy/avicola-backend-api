<?php

namespace App\Services\Asistente\Guardrails\Entrada;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Detecta insultos y agresiones; responde con un mensaje neutro sin escalar el conflicto.
 */
final class ProfanityAndToxicityGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_ENTRADA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $texto = $this->normalizar($ctx->mensaje);

        if ($this->coincideAlguno($this->patrones('toxicidad'), $texto)) {
            return ResultadoGuardrail::bloquea(
                $this->mensaje('profesionalismo'),
                'Lenguaje soez o agresivo en el mensaje del operador.'
            );
        }

        return ResultadoGuardrail::pasa();
    }
}
