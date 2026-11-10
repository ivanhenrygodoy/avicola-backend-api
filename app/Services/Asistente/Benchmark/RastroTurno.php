<?php

namespace App\Services\Asistente\Benchmark;

use App\Models\FuncionLogAgent;

/**
 * Acumula lo ocurrido durante un turno del asistente (tools, tokens, guards, resultado)
 * para que FuncionLogAgentService lo persista. No toca la base de datos.
 */
class RastroTurno
{
    public string $resultado = FuncionLogAgent::RESULTADO_EXITOSO;

    public ?string $respuestaAgente = null;

    public ?string $respuestaOriginalLlm = null;

    /** @var array<int, array{guard: string, fase: string, accion: string, detalle: string}> */
    public array $guards = [];

    /** @var array<int, array{herramienta: string, argumentos: array<string, mixed>, tablas: array<int, string>, resumen: string, exito: bool, duracion_ms: int}> */
    public array $tools = [];

    public int $tokensPrompt = 0;

    public int $tokensRespuesta = 0;

    public int $iteraciones = 0;

    public float $inicio;

    public ?string $error = null;

    public function __construct(
        public string $mensaje,
        public int $turnosHistorial = 0,
        public ?string $uuidConversacion = null,
    ) {
        $this->inicio = microtime(true);
    }

    /**
     * Suma el consumo de tokens (usageMetadata) de una respuesta de Gemini; ausente cuenta como 0.
     *
     * @param  array<string, mixed>  $respuestaGemini
     */
    public function agregarTokens(array $respuestaGemini): void
    {
        $uso = (array) ($respuestaGemini['usageMetadata'] ?? []);

        $this->tokensPrompt += (int) ($uso['promptTokenCount'] ?? 0);
        $this->tokensRespuesta += (int) ($uso['candidatesTokenCount'] ?? 0);
    }

    /**
     * @param  array<int, array{guard: string, fase: string, accion: string, detalle: string}>  $eventos
     */
    public function agregarEventos(array $eventos): void
    {
        foreach ($eventos as $evento) {
            $this->guards[] = $evento;
        }
    }
}
