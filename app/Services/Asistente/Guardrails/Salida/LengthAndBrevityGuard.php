<?php

namespace App\Services\Asistente\Guardrails\Salida;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Detecta respuestas demasiado largas para el widget lateral. Solo avisa: recortar a ciegas
 * podría dejar una respuesta incompleta o incorrecta.
 */
final class LengthAndBrevityGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_SALIDA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $maximo = (int) config('guardrails.largo_maximo_respuesta', 700);
        $largo = mb_strlen((string) $ctx->respuesta);

        if ($largo > $maximo) {
            return ResultadoGuardrail::avisa("Respuesta de {$largo} caracteres; el máximo recomendado es {$maximo}.");
        }

        return ResultadoGuardrail::pasa();
    }
}
