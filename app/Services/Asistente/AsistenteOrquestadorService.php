<?php

namespace App\Services\Asistente;

/**
 * Orquestador del ciclo de Function Calling entre el usuario, Google Gemini y las herramientas autorizadas.
 */
class AsistenteOrquestadorService
{
    public const MAX_ITERACIONES = 3;

    public function __construct(
        private readonly GeminiApiClient $client,
        private readonly AsistenteToolRegistry $registry
    ) {}

    /**
     * Procesa una conversación, ejecuta llamadas a herramientas si el LLM lo solicita,
     * y retorna la respuesta final redactada junto con la lista de herramientas utilizadas.
     *
     * @param  array<int, array<string, mixed>>  $historial
     * @param  array<string, mixed>  $contextoFijo
     * @return array{respuesta: string, herramientas_utilizadas: array<int, string>}
     */
    public function procesarConversacion(string $mensaje, array $historial = [], array $contextoFijo = []): array
    {
        $systemPrompt = PromptComposer::componer();
        $tools = $this->registry->obtenerDeclaracionesTools();

        // Armar contents inicial combinando historial previo y el mensaje actual
        $contents = [];

        foreach ($historial as $turno) {
            if (isset($turno['role'], $turno['parts']) && is_array($turno['parts'])) {
                $contents[] = [
                    'role' => $turno['role'],
                    'parts' => $turno['parts'],
                ];
            }
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [
                ['text' => $mensaje],
            ],
        ];

        $herramientasUtilizadas = [];

        for ($iteracion = 0; $iteracion < self::MAX_ITERACIONES; $iteracion++) {
            $response = $this->client->generarContenido($contents, $tools, $systemPrompt);

            $candidate = $response['candidates'][0] ?? null;
            $parts = $candidate['content']['parts'] ?? [];

            // Detectar si el modelo solicitó una llamada a función
            $functionCallPart = null;
            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    $functionCallPart = $part;
                    break;
                }
            }

            if ($functionCallPart !== null) {
                $call = $functionCallPart['functionCall'];
                $toolName = (string) ($call['name'] ?? '');
                $toolArgs = (array) ($call['args'] ?? []);

                $herramientasUtilizadas[] = $toolName;

                // Ejecutar la tool en el backend inyectando el contexto fijo seguro
                $toolResult = $this->registry->tiene($toolName)
                    ? $this->registry->ejecutar($toolName, $toolArgs, $contextoFijo)
                    : ['error' => "La herramienta [{$toolName}] no existe o no está autorizada."];

                // Agregar el turno del modelo con su functionCall a la historia
                $contents[] = [
                    'role' => 'model',
                    'parts' => [$functionCallPart],
                ];

                // Reinyectar el resultado de la función con rol 'function'
                $contents[] = [
                    'role' => 'function',
                    'parts' => [
                        [
                            'functionResponse' => [
                                'name' => $toolName,
                                'response' => [
                                    'name' => $toolName,
                                    'content' => $toolResult,
                                ],
                            ],
                        ],
                    ],
                ];

                // Siguiente iteración: Gemini leerá functionResponse y redactará o pedirá otra tool
                continue;
            }

            // No hubo functionCall: Gemini respondió con texto final
            $textos = [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $textos[] = $part['text'];
                }
            }

            $respuestaFinal = trim(implode("\n", $textos));

            return [
                'respuesta' => $respuestaFinal !== '' ? $respuestaFinal : 'No fue posible generar una respuesta.',
                'herramientas_utilizadas' => array_values(array_unique($herramientasUtilizadas)),
            ];
        }

        return [
            'respuesta' => 'Se ha alcanzado el límite de consultas consecutivas a las herramientas de la granja. Por favor intenta reformular tu pregunta.',
            'herramientas_utilizadas' => array_values(array_unique($herramientasUtilizadas)),
        ];
    }
}

