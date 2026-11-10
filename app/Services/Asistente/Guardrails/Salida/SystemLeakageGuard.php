<?php

namespace App\Services\Asistente\Guardrails\Salida;

use App\Services\Asistente\Guardrails\ContextoGuardrail;
use App\Services\Asistente\Guardrails\GuardrailBase;
use App\Services\Asistente\Guardrails\ResultadoGuardrail;
use App\Services\Asistente\ModuloNavigationResolver;

/**
 * Evita que la respuesta exponga detalles técnicos: tablas (mnt_/ctl_), rutas de código, trazas PHP,
 * errores de cURL/SQL o la API key en una URL. Los nombres de tools se reemplazan sin bloquear
 * (reutiliza ModuloNavigationResolver::sanitizarRespuesta).
 */
final class SystemLeakageGuard extends GuardrailBase
{
    /**
     * Fragmentos que delatan información interna. Si aparecen, la respuesta se reemplaza completa.
     *
     * @var array<int, string>
     */
    private const PATRONES_FUGA = [
        '/\b(?:mnt|ctl)_[a-z0-9_]+/i',
        '/\b(?:app|vendor|storage|bootstrap|routes|config|database)\/[\w\-\/.]+/i',
        '/[A-Za-z]:\\\\[\w\-\\\\. ]+/',
        '/\/(?:var|usr|home|etc)\/[\w\-\/.]+/',
        '/stack trace/i',
        '/#\d+\s+\S+\.php/i',
        '/curl error\s*\d*/i',
        '/SQLSTATE\[[^\]]*\]/i',
    ];

    public function __construct(private readonly ModuloNavigationResolver $resolver = new ModuloNavigationResolver) {}

    public function fase(): string
    {
        return self::FASE_SALIDA;
    }

    public function evaluar(ContextoGuardrail $ctx): ResultadoGuardrail
    {
        $respuesta = (string) $ctx->respuesta;

        if ($this->contieneFuga($respuesta)) {
            return ResultadoGuardrail::bloquea(
                $this->mensaje('fuga'),
                'La respuesta exponía detalles técnicos internos y fue reemplazada.'
            );
        }

        $sanitizada = $this->resolver->sanitizarRespuesta($respuesta);

        if ($sanitizada !== $respuesta) {
            return ResultadoGuardrail::modifica($sanitizada, 'Se reemplazaron nombres internos de herramientas por módulos operativos.');
        }

        return ResultadoGuardrail::pasa();
    }

    /**
     * Redacta fragmentos sensibles de un texto (errores, mensajes de excepción) antes de guardarlo o mostrarlo.
     */
    public function sanear(string $texto): string
    {
        $limpio = $this->resolver->sanitizarRespuesta($texto);
        $limpio = (string) preg_replace('/([?&]key=)[^&\s"\']+/i', '$1[REDACTADO]', $limpio);

        foreach (self::PATRONES_FUGA as $patron) {
            $limpio = (string) preg_replace($patron, '[REDACTADO]', $limpio);
        }

        return $limpio;
    }

    private function contieneFuga(string $texto): bool
    {
        foreach (self::PATRONES_FUGA as $patron) {
            if (preg_match($patron, $texto) === 1) {
                return true;
            }
        }

        return preg_match('/[?&]key=[^&\s"\']+/i', $texto) === 1;
    }
}
