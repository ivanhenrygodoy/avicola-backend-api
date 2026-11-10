<?php

namespace App\Services\Asistente\Guardrails\Tool;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;
use Carbon\CarbonImmutable;

/**
 * Valida los argumentos que el modelo entrega a una tool antes de tocar PostgreSQL:
 * fechas en Y-m-d reales, rango coherente (fin >= inicio) y especies permitidas.
 * Si falla, el orquestador no ejecuta la tool y devuelve este mensaje al modelo para que pida aclaración.
 */
final class ToolArgumentValidationGuard extends GuardrailBase
{
    private const CLAVES_INICIO = ['fecha_inicio', 'fecha_desde'];

    private const CLAVES_FIN = ['fecha_fin', 'fecha_hasta'];

    private const ESPECIES = ['Gallina', 'Chompipe', 'Pato'];

    public function fase(): string
    {
        return self::FASE_TOOL;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $argumentos = $ctx->argumentos;
        $fechas = [];

        foreach (array_merge(self::CLAVES_INICIO, self::CLAVES_FIN) as $clave) {
            if (! $this->tieneValor($argumentos, $clave)) {
                continue;
            }

            $fecha = $this->parsearFecha($argumentos[$clave]);
            if ($fecha === null) {
                return ResultadoGuardrail::bloquea(
                    "El argumento [{$clave}] debe ser una fecha real en formato Y-m-d (ejemplo: 2026-11-09). Pide al operario aclarar la fecha.",
                    "Fecha inválida en {$clave}."
                );
            }

            $fechas[$clave] = $fecha;
        }

        $inicio = $this->primera($fechas, self::CLAVES_INICIO);
        $fin = $this->primera($fechas, self::CLAVES_FIN);

        if ($inicio !== null && $fin !== null && $fin->lessThan($inicio)) {
            return ResultadoGuardrail::bloquea(
                'La fecha final es anterior a la fecha inicial. Pide al operario aclarar el rango de fechas.',
                'Rango de fechas incoherente (fin < inicio).'
            );
        }

        if ($this->tieneValor($argumentos, 'especie') && ! $this->especiePermitida($argumentos['especie'])) {
            return ResultadoGuardrail::bloquea(
                'La especie debe ser una de: '.implode(', ', self::ESPECIES).'. Pide al operario aclarar la especie.',
                'Especie no permitida.'
            );
        }

        return ResultadoGuardrail::pasa();
    }

    /**
     * @param  array<string, mixed>  $argumentos
     */
    private function tieneValor(array $argumentos, string $clave): bool
    {
        return array_key_exists($clave, $argumentos)
            && $argumentos[$clave] !== null
            && $argumentos[$clave] !== '';
    }

    private function parsearFecha(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor)) {
            return null;
        }

        // DateTimeImmutable devuelve false (Carbon lanzaría excepción) ante un formato inválido.
        $fecha = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        // createFromFormat acepta desbordes (2026-02-30 → 2026-03-02): se exige el mismo texto al formatear.
        return $fecha !== false && $fecha->format('Y-m-d') === $valor ? CarbonImmutable::instance($fecha) : null;
    }

    /**
     * @param  array<string, CarbonImmutable>  $fechas
     * @param  array<int, string>  $claves
     */
    private function primera(array $fechas, array $claves): ?CarbonImmutable
    {
        foreach ($claves as $clave) {
            if (isset($fechas[$clave])) {
                return $fechas[$clave];
            }
        }

        return null;
    }

    private function especiePermitida(mixed $especie): bool
    {
        if (! is_string($especie)) {
            return false;
        }

        $permitidas = array_map('mb_strtolower', self::ESPECIES);

        return in_array(mb_strtolower(trim($especie)), $permitidas, true);
    }
}
