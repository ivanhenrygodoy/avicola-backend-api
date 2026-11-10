<?php

namespace App\Services\Asistente\Guardrails\Salida;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Garantiza que las fechas mostradas al operario usen el formato de negocio DD-MM-YYYY
 * y no formatos crudos de base de datos (Y-m-d o ISO 8601).
 */
final class DateFormatGuard extends GuardrailBase
{
    private const PATRON = '/\b(\d{4})-(\d{2})-(\d{2})(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+\-]\d{2}:?\d{2})?)?(?!\d)/';

    public function fase(): string
    {
        return self::FASE_SALIDA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $original = (string) $ctx->respuesta;

        $convertido = (string) preg_replace_callback(self::PATRON, function (array $m): string {
            // Una fecha imposible (p. ej. 2026-13-45) probablemente es un código, no una fecha: no se toca.
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "{$m[3]}-{$m[2]}-{$m[1]}" : $m[0];
        }, $original);

        if ($convertido === $original) {
            return ResultadoGuardrail::pasa();
        }

        return ResultadoGuardrail::modifica($convertido, 'Fechas convertidas al formato DD-MM-YYYY.');
    }
}
