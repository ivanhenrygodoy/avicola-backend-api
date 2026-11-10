<?php

namespace App\Services\Asistente\Guardrails;

use App\Services\Asistente\Guardrails\Contracts\GuardrailInterface;

/**
 * Ejecuta los guardrails registrados por fase y acumula los eventos para la auditoría.
 */
class GuardrailPipeline
{
    /**
     * @var array<int, GuardrailInterface>
     */
    private array $guards = [];

    /**
     * @param  iterable<GuardrailInterface>  $guards
     */
    public function __construct(iterable $guards = [])
    {
        foreach ($guards as $guard) {
            $this->guards[] = $guard;
        }
    }

    public function total(): int
    {
        return count($this->guards);
    }

    public function evaluarEntrada(string $mensaje): ResultadoPipeline
    {
        return $this->cortarEnPrimerBloqueo(GuardrailInterface::FASE_ENTRADA, new ContextoGuardrail(mensaje: $mensaje));
    }

    /**
     * @param  array<string, mixed>  $argumentos
     */
    public function evaluarTool(string $herramienta, array $argumentos): ResultadoPipeline
    {
        return $this->cortarEnPrimerBloqueo(
            GuardrailInterface::FASE_TOOL,
            new ContextoGuardrail(mensaje: '', herramienta: $herramienta, argumentos: $argumentos)
        );
    }

    public function evaluarBucle(int $iteracion): ResultadoPipeline
    {
        return $this->cortarEnPrimerBloqueo(
            GuardrailInterface::FASE_BUCLE,
            new ContextoGuardrail(mensaje: '', iteracion: $iteracion)
        );
    }

    /**
     * Cada modificación alimenta al guard siguiente; un bloqueo reemplaza todo el texto y corta.
     *
     * @param  array<int, string>  $herramientasUtilizadas
     */
    public function evaluarSalida(string $respuesta, array $herramientasUtilizadas): ResultadoPipeline
    {
        $texto = $respuesta;
        $eventos = [];

        foreach ($this->deFase(GuardrailInterface::FASE_SALIDA) as $guard) {
            $resultado = $guard->evaluar(new ContextoGuardrail(
                mensaje: '',
                respuesta: $texto,
                herramientasUtilizadas: $herramientasUtilizadas,
            ));

            $this->registrarEvento($eventos, $guard, $resultado);

            if ($resultado->accion === ResultadoGuardrail::BLOQUEO) {
                return new ResultadoPipeline(detenido: true, texto: (string) $resultado->texto, eventos: $eventos);
            }

            if ($resultado->accion === ResultadoGuardrail::MODIFICACION && $resultado->texto !== null) {
                $texto = $resultado->texto;
            }
        }

        return new ResultadoPipeline(detenido: false, texto: $texto, eventos: $eventos);
    }

    private function cortarEnPrimerBloqueo(string $fase, ContextoGuardrail $ctx): ResultadoPipeline
    {
        $eventos = [];

        foreach ($this->deFase($fase) as $guard) {
            $resultado = $guard->evaluar($ctx);
            $this->registrarEvento($eventos, $guard, $resultado);

            if (in_array($resultado->accion, [ResultadoGuardrail::BLOQUEO, ResultadoGuardrail::FAST_PATH], true)) {
                return new ResultadoPipeline(
                    detenido: true,
                    texto: $resultado->texto,
                    fastPath: $resultado->accion === ResultadoGuardrail::FAST_PATH,
                    eventos: $eventos,
                );
            }
        }

        return new ResultadoPipeline(detenido: false, eventos: $eventos);
    }

    /**
     * @return array<int, GuardrailInterface>
     */
    private function deFase(string $fase): array
    {
        return array_values(array_filter($this->guards, fn (GuardrailInterface $g) => $g->fase() === $fase));
    }

    /**
     * @param  array<int, array<string, string>>  $eventos
     */
    private function registrarEvento(array &$eventos, GuardrailInterface $guard, ResultadoGuardrail $resultado): void
    {
        if ($resultado->accion === ResultadoGuardrail::PASA) {
            return;
        }

        $eventos[] = [
            'guard' => $guard->nombre(),
            'fase' => $guard->fase(),
            'accion' => $resultado->accion === ResultadoGuardrail::FAST_PATH ? 'bloqueo' : $resultado->accion,
            'detalle' => $resultado->detalle,
        ];
    }
}
