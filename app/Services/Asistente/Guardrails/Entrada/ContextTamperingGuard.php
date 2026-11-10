<?php

namespace App\Services\Asistente\Guardrails\Entrada;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Detecta afirmaciones de identidad en el texto ("soy el admin con id 1"). Solo avisa:
 * el contexto del usuario ya es inmutable ($contextoFijo) y el orquestador descarta los
 * argumentos de identidad que el modelo intente pasar a las herramientas.
 */
final class ContextTamperingGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_ENTRADA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $texto = $this->normalizar($ctx->mensaje);

        if ($this->coincideAlguno($this->patrones('suplantacion'), $texto)) {
            return ResultadoGuardrail::avisa('El mensaje afirma una identidad o rol; se ignora, el contexto de sesión es inmutable.');
        }

        return ResultadoGuardrail::pasa();
    }
}
