<?php

namespace App\Services\Asistente\Guardrails\Entrada;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Mitiga intentos de jailbreak o extracción del system prompt y de las herramientas.
 */
final class PromptInjectionGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_ENTRADA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $texto = $this->normalizar($ctx->mensaje);

        if ($this->coincideAlguno($this->patrones('prompt_injection'), $texto)) {
            return ResultadoGuardrail::bloquea(
                $this->mensaje('injection'),
                'Intento de manipulación de instrucciones o extracción del prompt.'
            );
        }

        return ResultadoGuardrail::pasa();
    }
}
