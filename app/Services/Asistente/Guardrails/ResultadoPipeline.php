<?php

namespace App\Services\Asistente\Guardrails;

/**
 * Resultado agregado de ejecutar los guardrails de una fase.
 */
final class ResultadoPipeline
{
    /**
     * @param  array<int, array{guard: string, fase: string, accion: string, detalle: string}>  $eventos
     */
    public function __construct(
        public readonly bool $detenido = false,
        public readonly ?string $texto = null,
        public readonly bool $fastPath = false,
        public readonly array $eventos = [],
    ) {}
}
