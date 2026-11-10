<?php

namespace App\Services\Asistente\Guardrails;

/**
 * Veredicto de un guardrail sobre un turno.
 */
final class ResultadoGuardrail
{
    public const PASA = 'pasa';

    public const BLOQUEO = 'bloqueo';

    public const FAST_PATH = 'fast_path';

    public const MODIFICACION = 'modificacion';

    public const AVISO = 'aviso';

    private function __construct(
        public readonly string $accion,
        public readonly ?string $texto = null,
        public readonly string $detalle = '',
    ) {}

    public static function pasa(): self
    {
        return new self(self::PASA);
    }

    public static function bloquea(string $texto, string $detalle = ''): self
    {
        return new self(self::BLOQUEO, $texto, $detalle);
    }

    public static function fastPath(string $texto, string $detalle = ''): self
    {
        return new self(self::FAST_PATH, $texto, $detalle);
    }

    public static function modifica(string $texto, string $detalle = ''): self
    {
        return new self(self::MODIFICACION, $texto, $detalle);
    }

    public static function avisa(string $detalle): self
    {
        return new self(self::AVISO, null, $detalle);
    }
}
