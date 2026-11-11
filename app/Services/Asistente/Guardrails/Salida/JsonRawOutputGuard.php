<?php

namespace App\Services\Asistente\Guardrails\Salida;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;

/**
 * Detecta si el modelo generó una respuesta estructurada en formato JSON crudo
 * (por ejemplo `{"respuesta": "..."}` o envuelta en bloques markdown ```json).
 * Extrae el mensaje en lenguaje natural o bloquea el volcado técnico.
 */
final class JsonRawOutputGuard extends GuardrailBase
{
    public function fase(): string
    {
        return self::FASE_SALIDA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $original = trim((string) $ctx->respuesta);

        if ($original === '') {
            return ResultadoGuardrail::pasa();
        }

        $extraido = $this->extraerDeMarkdown($original);
        $candidato = $extraido ?? $original;

        // Comprobar si parece JSON (comienza con { o [ y termina con } o ])
        if (! (str_starts_with($candidato, '{') && str_ends_with($candidato, '}'))) {
            return ResultadoGuardrail::pasa();
        }

        $decodificado = json_decode($candidato, true);

        if (! is_array($decodificado)) {
            return ResultadoGuardrail::pasa();
        }

        $textoConversacional = $this->buscarTextoConversacional($decodificado);

        if ($textoConversacional !== null && trim($textoConversacional) !== '') {
            return ResultadoGuardrail::modifica(
                trim($textoConversacional),
                'Se extrajo el mensaje en lenguaje natural desde una estructura JSON cruda generada por el modelo.'
            );
        }

        return ResultadoGuardrail::bloquea(
            $this->mensaje('fuga'),
            'La respuesta contenía una estructura JSON técnica cruda y fue reemplazada.'
        );
    }

    private function extraerDeMarkdown(string $texto): ?string
    {
        if (preg_match('/^```(?:json)?\s*(\{[\s\S]*\})\s*```$/i', $texto, $coincidencias) === 1) {
            return trim($coincidencias[1]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function buscarTextoConversacional(array $datos): ?string
    {
        $prioridades = ['respuesta', 'mensaje', 'message', 'response', 'texto', 'text', 'content'];

        foreach ($prioridades as $clave) {
            if (isset($datos[$clave]) && is_string($datos[$clave])) {
                return $datos[$clave];
            }
        }

        if (count($datos) === 1) {
            $primerValor = reset($datos);
            if (is_string($primerValor)) {
                return $primerValor;
            }
        }

        return null;
    }
}
