<?php

namespace App\Services\Asistente\Guardrails\Entrada;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Bloquea solicitudes claramente ajenas al contexto agropecuario de la granja
 * (poemas, deportes, programación…). Es conservador: ante duda, deja pasar y lo registra el log.
 */
final class ScopeEnforcementGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_ENTRADA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $texto = $this->normalizar($ctx->mensaje);

        if ($this->coincideAlguno($this->patrones('tema_ajeno'), $texto) && ! $this->mencionaVocabularioGranja($texto)) {
            return ResultadoGuardrail::bloquea(
                $this->mensaje('alcance'),
                'Solicitud fuera del dominio operativo de la granja.'
            );
        }

        return ResultadoGuardrail::pasa();
    }
}
