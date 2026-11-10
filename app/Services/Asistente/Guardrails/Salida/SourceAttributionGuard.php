<?php

namespace App\Services\Asistente\Guardrails\Salida;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Si se consultaron los manuales técnicos, la respuesta debe citar el manual. Solo avisa en el log:
 * no reescribe la respuesta.
 */
final class SourceAttributionGuard extends GuardrailBase
{
    private const TOOL_MANUALES = 'consultar_manuales_tecnicos';

    public function fase(): string
    {
        return self::FASE_SALIDA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        if (! in_array(self::TOOL_MANUALES, $ctx->herramientasUtilizadas, true)) {
            return ResultadoGuardrail::pasa();
        }

        if (preg_match('/\bmanual(es)?\b/iu', (string) $ctx->respuesta) === 1) {
            return ResultadoGuardrail::pasa();
        }

        return ResultadoGuardrail::avisa('Se consultaron los manuales técnicos pero la respuesta no cita el manual.');
    }
}
