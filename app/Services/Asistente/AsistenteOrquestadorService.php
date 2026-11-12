<?php

namespace App\Services\Asistente;

use App\Models\FuncionLogAgent;
use App\Services\Asistente\Benchmark\FuncionLogAgentService;
use App\Services\Asistente\Benchmark\RastroTurno;
use App\Services\Asistente\Guardrails\Bucle\ToolLoopGuard;
use App\Services\Asistente\Guardrails\GuardrailPipeline;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orquestador del ciclo de Function Calling entre el usuario, Google Gemini y las herramientas autorizadas.
 *
 * Los guardrails (entrada, tool, bucle y salida) viven en GuardrailPipeline y cada turno queda
 * auditado en la bitácora del Benchmark (FuncionLogAgentService), sin afectar nunca la respuesta.
 */
class AsistenteOrquestadorService
{
    private readonly GuardrailPipeline $pipeline;

    private readonly FuncionLogAgentService $recorder;

    public function __construct(
        private readonly GeminiApiClient $client,
        private readonly AsistenteToolRegistry $registry,
        private readonly ModuloNavigationResolver $resolver = new ModuloNavigationResolver,
        ?GuardrailPipeline $pipeline = null,
        ?FuncionLogAgentService $recorder = null,
    ) {
        $this->pipeline = $pipeline ?? app(GuardrailPipeline::class);
        $this->recorder = $recorder ?? app(FuncionLogAgentService::class);
    }

    /**
     * Procesa una conversación, ejecuta llamadas a herramientas si el LLM lo solicita,
     * y retorna la respuesta final redactada junto con la lista de herramientas utilizadas.
     *
     * @param  array<int, array<string, mixed>>  $historial
     * @param  array<string, mixed>  $contextoFijo
     * @return array{respuesta: string, herramientas_utilizadas: array<int, string>, sugerencias_modulos: array<int, array<string, string>>, resultado: string}
     */
    public function procesarConversacion(string $mensaje, array $historial = [], array $contextoFijo = [], ?string $uuidConversacion = null): array
    {
        $rastro = new RastroTurno($mensaje, count($historial), $uuidConversacion);

        Log::info('[AviaCore] Petición recibida en asistente', [
            'mensaje' => $mensaje,
            'turnos_historial' => count($historial),
            'id_usuario' => $contextoFijo['id_usuario'] ?? null,
        ]);

        try {
            $resultado = $this->ejecutarTurno($mensaje, $historial, $contextoFijo, $rastro);
        } catch (Throwable $e) {
            $rastro->resultado = FuncionLogAgent::RESULTADO_ERROR;
            $rastro->error = $e->getMessage();
            $this->registrar($rastro);

            // Se relanza: el controlador conserva su respuesta 503 y su registro completo en laravel.log.
            throw $e;
        }

        $rastro->respuestaAgente = $resultado['respuesta'];
        $this->registrar($rastro);

        // Resultado del turno (exitoso, fast_path o bloqueado_guard): el controlador lo usa para decidir
        // qué entra al contexto de la conversación. No se expone al cliente.
        $resultado['resultado'] = $rastro->resultado;

        return $resultado;
    }

    /**
     * @param  array<int, array<string, mixed>>  $historial
     * @param  array<string, mixed>  $contextoFijo
     * @return array{respuesta: string, herramientas_utilizadas: array<int, string>, sugerencias_modulos: array<int, array<string, string>>}
     */
    private function ejecutarTurno(string $mensaje, array $historial, array $contextoFijo, RastroTurno $rastro): array
    {
        // Guardrails de entrada: el primero que bloquea corta el turno antes de llamar a Gemini (0 tokens).
        $entrada = $this->pipeline->evaluarEntrada($mensaje);
        $rastro->agregarEventos($entrada->eventos);

        if ($entrada->detenido) {
            $rastro->resultado = $entrada->fastPath
                ? FuncionLogAgent::RESULTADO_FAST_PATH
                : FuncionLogAgent::RESULTADO_BLOQUEADO;

            Log::info('[AviaCore] Turno resuelto por guardrail de entrada (0 tokens consumidos)', [
                'mensaje' => $mensaje,
                'resultado' => $rastro->resultado,
                'duracion_ms' => $this->duracionMs($rastro),
            ]);

            return $this->resultado((string) $entrada->texto, []);
        }

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

        for ($iteracion = 0; $iteracion < ToolLoopGuard::LIMITE; $iteracion++) {
            $response = $this->client->generarContenido($contents, $tools, $systemPrompt);
            $rastro->iteraciones++;
            $rastro->agregarTokens($response);

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
                // La identidad solo viaja en $contextoFijo: se descartan los argumentos de identidad que genere el LLM.
                $toolArgs = FuncionLogAgentService::sinIdentidad((array) ($call['args'] ?? []));

                $herramientasUtilizadas[] = $toolName;

                Log::info('[AviaCore] Gemini solicitó ejecución de Tool', [
                    'iteracion' => $iteracion + 1,
                    'herramienta' => $toolName,
                    'argumentos' => $toolArgs,
                ]);

                $toolResult = $this->ejecutarTool($toolName, $toolArgs, $contextoFijo, $rastro);

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

            $respuestaLlm = trim(implode("\n", $textos));

            // Guardrails de salida: modifican o reemplazan la respuesta (fuga técnica, fechas, avisos).
            $salida = $this->pipeline->evaluarSalida($respuestaLlm, $herramientasUtilizadas);
            $rastro->agregarEventos($salida->eventos);

            if ($salida->detenido) {
                $rastro->resultado = FuncionLogAgent::RESULTADO_BLOQUEADO;
                $rastro->respuestaOriginalLlm = $respuestaLlm;
            }

            $respuestaFinal = (string) $salida->texto;
            $sugerencias = $this->resolver->resolver($respuestaFinal, $herramientasUtilizadas);

            Log::info('[AviaCore] Respuesta final generada', [
                'respuesta' => mb_substr($respuestaFinal, 0, 150).(mb_strlen($respuestaFinal) > 150 ? '...' : ''),
                'herramientas' => array_values(array_unique($herramientasUtilizadas)),
                'modulos_sugeridos' => array_column($sugerencias, 'titulo'),
                'duracion_ms' => $this->duracionMs($rastro),
            ]);

            return [
                'respuesta' => $respuestaFinal !== '' ? $respuestaFinal : 'No fue posible generar una respuesta.',
                'herramientas_utilizadas' => array_values(array_unique($herramientasUtilizadas)),
                'sugerencias_modulos' => $sugerencias,
            ];
        }

        // Se agotaron las iteraciones permitidas: el guard de bucle define la respuesta de contingencia.
        $bucle = $this->pipeline->evaluarBucle(ToolLoopGuard::LIMITE);
        $rastro->agregarEventos($bucle->eventos);
        $rastro->resultado = FuncionLogAgent::RESULTADO_BLOQUEADO;

        Log::warning('[AviaCore] Se alcanzó el límite máximo de iteraciones consecutivas de Tools', [
            'herramientas_utilizadas' => array_values(array_unique($herramientasUtilizadas)),
            'duracion_ms' => $this->duracionMs($rastro),
        ]);

        return $this->resultado((string) $bucle->texto, array_values(array_unique($herramientasUtilizadas)));
    }

    /**
     * Ejecuta una tool (si existe y sus argumentos son válidos) y deja constancia en el rastro del turno.
     *
     * @param  array<string, mixed>  $argumentos
     * @param  array<string, mixed>  $contextoFijo
     * @return array<string, mixed>
     */
    private function ejecutarTool(string $nombre, array $argumentos, array $contextoFijo, RastroTurno $rastro): array
    {
        $inicio = microtime(true);
        $tablas = [];
        $exito = true;

        if (! $this->registry->tiene($nombre)) {
            $resultado = ['error' => "La herramienta [{$nombre}] no existe o no está autorizada."];
            $exito = false;
        } else {
            $tablas = $this->registry->obtener($nombre)->tablasConsultadas();
            $validacion = $this->pipeline->evaluarTool($nombre, $argumentos);
            $rastro->agregarEventos($validacion->eventos);

            if ($validacion->detenido) {
                // No se toca la base de datos: el modelo recibe el motivo y puede pedir aclaración al operario.
                $resultado = ['error' => (string) $validacion->texto];
                $exito = false;
            } else {
                $resultado = $this->registry->ejecutar($nombre, $argumentos, $contextoFijo);
            }
        }

        Log::info('[AviaCore] Resultado de Tool ejecutada', [
            'herramienta' => $nombre,
            'resultado_preview' => isset($resultado['mensaje']) ? $resultado['mensaje'] : ($exito ? 'Datos obtenidos' : 'Sin ejecutar'),
            'total_fragmentos' => $resultado['total_fragmentos'] ?? null,
        ]);

        $rastro->tools[] = [
            'herramienta' => $nombre,
            'argumentos' => $argumentos,
            'tablas' => $exito ? $tablas : [],
            'resumen' => (string) ($resultado['mensaje'] ?? $resultado['error'] ?? 'Datos obtenidos'),
            'exito' => $exito,
            'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];

        return $resultado;
    }

    /**
     * Registrar la auditoría nunca debe romper el chat, ni siquiera si el registrador falla de forma inesperada.
     */
    private function registrar(RastroTurno $rastro): void
    {
        try {
            $this->recorder->registrar($rastro);
        } catch (Throwable $e) {
            Log::error('[AviaCore] Falló el registrador de auditoría del asistente: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, string>  $herramientas
     * @return array{respuesta: string, herramientas_utilizadas: array<int, string>, sugerencias_modulos: array<int, array<string, string>>}
     */
    private function resultado(string $respuesta, array $herramientas): array
    {
        return [
            'respuesta' => $respuesta,
            'herramientas_utilizadas' => $herramientas,
            'sugerencias_modulos' => [],
        ];
    }

    private function duracionMs(RastroTurno $rastro): float
    {
        return round((microtime(true) - $rastro->inicio) * 1000, 2);
    }
}
