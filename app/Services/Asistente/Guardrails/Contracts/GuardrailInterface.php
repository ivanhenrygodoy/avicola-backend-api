<?php

namespace App\Services\Asistente\Guardrails\Contracts;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Contrato común de los guardrails del asistente AviaCore.
 */
interface GuardrailInterface
{
    public const FASE_ENTRADA = 'entrada';

    public const FASE_TOOL = 'tool';

    public const FASE_BUCLE = 'bucle';

    public const FASE_SALIDA = 'salida';

    /**
     * Nombre corto de la clase (se guarda en la bitácora de auditoría).
     */
    public function nombre(): string;

    /**
     * Momento del turno en que se evalúa: entrada | tool | bucle | salida.
     */
    public function fase(): string;

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail;
}
