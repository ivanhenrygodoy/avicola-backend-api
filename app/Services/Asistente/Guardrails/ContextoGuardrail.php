<?php

namespace App\Services\Asistente\Guardrails;

/**
 * Datos del turno disponibles para un guardrail.
 */
final class ContextoGuardrail
{
    /**
     * @param  array<int, string>  $herramientasUtilizadas
     * @param  array<string, mixed>  $argumentos
     */
    public function __construct(
        public readonly string $mensaje,
        public readonly ?string $respuesta = null,
        public readonly array $herramientasUtilizadas = [],
        public readonly ?string $herramienta = null,
        public readonly array $argumentos = [],
        public readonly int $iteracion = 0,
    ) {}
}
