<?php

namespace App\Services\Asistente\Guardrails\Entrada;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;
use App\Services\Asistente\SaludoDetectorService;

/**
 * Vía rápida para saludos, cortesías y presentaciones: responde sin consumir tokens.
 * Reutiliza SaludoDetectorService (la lógica de detección no se duplica).
 */
final class SaludoFastPathGuard extends GuardrailBase
{
    public function __construct(private readonly SaludoDetectorService $saludos = new SaludoDetectorService) {}

    public function fase(): string
    {
        return self::FASE_ENTRADA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        if (! $this->saludos->esSaludoPuro($ctx->mensaje)) {
            return ResultadoGuardrail::pasa();
        }

        return ResultadoGuardrail::fastPath(
            $this->saludos->obtenerRespuestaPredeterminada($ctx->mensaje),
            'Saludo o cortesía resuelto sin consumir tokens.'
        );
    }
}
