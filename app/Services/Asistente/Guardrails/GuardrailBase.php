<?php

namespace App\Services\Asistente\Guardrails;

use App\Services\Asistente\Guardrails\Contracts\GuardrailInterface;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Base común de los guardrails: nombre corto y utilidades de comparación de texto
 * (normalización sin acentos, patrones de config y vocabulario de la granja).
 */
abstract class GuardrailBase implements GuardrailInterface
{
    public function nombre(): string
    {
        return (new ReflectionClass($this))->getShortName();
    }

    /**
     * Minúsculas, sin acentos y con espacios colapsados, para que mayúsculas, tildes
     * o espacios extra no permitan evadir los patrones.
     */
    protected function normalizar(string $texto): string
    {
        $ascii = Str::lower(Str::ascii($texto));

        return trim((string) preg_replace('/\s+/u', ' ', $ascii));
    }

    /**
     * @param  array<int, string>  $patrones
     */
    protected function coincideAlguno(array $patrones, string $textoNormalizado): bool
    {
        foreach ($patrones as $patron) {
            if (preg_match($patron, $textoNormalizado) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function patrones(string $clave): array
    {
        return (array) config("guardrails.patrones.{$clave}", []);
    }

    protected function mensaje(string $clave): string
    {
        return (string) config("guardrails.mensajes.{$clave}", '');
    }

    protected function mencionaVocabularioGranja(string $textoNormalizado): bool
    {
        $palabras = preg_split('/[^a-z0-9]+/', $textoNormalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $vocabulario = (array) config('guardrails.vocabulario_granja', []);

        if (count(array_intersect($palabras, $vocabulario)) > 0) {
            return true;
        }

        // Raíces (recolect…, produc…, vacun…): cubren conjugaciones y plurales sin listarlas una a una.
        foreach ((array) config('guardrails.vocabulario_prefijos', []) as $prefijo) {
            foreach ($palabras as $palabra) {
                if (str_starts_with($palabra, $prefijo)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Quita las fechas del texto normalizado para que no se confundan con operaciones aritméticas.
     */
    protected function sinFechas(string $textoNormalizado): string
    {
        return (string) preg_replace($this->patrones('fecha'), ' ', $textoNormalizado);
    }
}
