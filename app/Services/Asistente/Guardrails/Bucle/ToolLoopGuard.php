<?php

namespace App\Services\Asistente\Guardrails\Bucle;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Limita las iteraciones consecutivas de Function Calling por mensaje para evitar bucles
 * infinitos que consuman tokens o bloqueen el worker.
 */
final class ToolLoopGuard extends GuardrailBase
{
    public const LIMITE = 3;

    public function fase(): string
    {
        return self::FASE_BUCLE;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        if ($ctx->iteracion >= self::LIMITE) {
            return ResultadoGuardrail::bloquea(
                $this->mensaje('limite_tools'),
                'Se alcanzó el máximo de '.self::LIMITE.' iteraciones de herramientas.'
            );
        }

        return ResultadoGuardrail::pasa();
    }
}
