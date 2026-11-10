<?php

namespace App\Services\Asistente\Benchmark;

use App\Models\FuncionLogAgent;
use App\Services\Asistente\Guardrails\Salida\SystemLeakageGuard;
use App\Services\Asistente\PromptComposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persiste la auditoría de cada turno del asistente en mnt_funcion_log_agent (+ tools).
 * Nunca interrumpe el chat: cualquier fallo se registra en laravel.log y retorna null.
 */
class FuncionLogAgentService
{
    /** Claves que identifican al usuario y jamás se guardan en los argumentos de las tools. */
    public const CLAVES_IDENTIDAD = ['id_usuario', 'user_id', 'usuario', 'email', 'id_user'];

    private const MAX_TEXTO = 300;

    public function __construct(private readonly SystemLeakageGuard $saneador = new SystemLeakageGuard) {}

    /**
     * Hash corto del prompt vigente: permite saber si el prompt cambió desde un turno.
     */
    public static function versionPromptVigente(): string
    {
        return substr(sha1(PromptComposer::componer()), 0, 12);
    }

    public function registrar(RastroTurno $rastro): ?FuncionLogAgent
    {
        try {
            return DB::transaction(function () use ($rastro) {
                $log = FuncionLogAgent::create([
                    'uuid_conversacion' => $rastro->uuidConversacion,
                    'mensaje_usuario' => $rastro->mensaje,
                    'turnos_historial' => $rastro->turnosHistorial,
                    'prompt_version' => self::versionPromptVigente(),
                    'respuesta_agente' => $rastro->respuestaAgente,
                    'respuesta_original_llm' => $rastro->respuestaOriginalLlm,
                    'resultado' => $rastro->resultado,
                    'guards_aplicados' => $rastro->guards,
                    'guards_nombres' => $this->nombresDeGuards($rastro->guards),
                    'error_mensaje' => $this->errorSaneado($rastro->error),
                    'tokens_prompt' => $rastro->tokensPrompt,
                    'tokens_respuesta' => $rastro->tokensRespuesta,
                    'tokens_total' => $rastro->tokensPrompt + $rastro->tokensRespuesta,
                    'iteraciones' => $rastro->iteraciones,
                    'duracion_ms' => (int) round((microtime(true) - $rastro->inicio) * 1000),
                    'modelo' => config('gemini.model'),
                    'created_at' => now(),
                ]);

                foreach (array_values($rastro->tools) as $i => $tool) {
                    $log->tools()->create([
                        'orden' => $i + 1,
                        'herramienta' => $tool['herramienta'],
                        'argumentos' => self::sinIdentidad($tool['argumentos']),
                        'tablas_consultadas' => $tool['tablas'],
                        'resumen_resultado' => $this->truncar($tool['resumen']),
                        'exito' => $tool['exito'],
                        'duracion_ms' => $tool['duracion_ms'],
                    ]);
                }

                return $log;
            });
        } catch (Throwable $e) {
            Log::error('[AviaCore] No se pudo registrar la auditoría del turno del asistente: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<int, array{guard: string}>  $guards
     */
    private function nombresDeGuards(array $guards): ?string
    {
        $nombres = array_values(array_unique(array_column($guards, 'guard')));

        return $nombres === [] ? null : '|'.implode('|', $nombres).'|';
    }

    private function errorSaneado(?string $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        return $this->truncar($this->saneador->sanear($error));
    }

    private function truncar(string $texto): string
    {
        return mb_substr($texto, 0, self::MAX_TEXTO);
    }

    /**
     * Elimina (de forma recursiva) las claves que identifican al usuario. Lo usa también el orquestador
     * para descartar argumentos de identidad que el modelo intente pasar a una tool.
     *
     * @param  array<string, mixed>  $argumentos
     * @return array<string, mixed>
     */
    public static function sinIdentidad(array $argumentos): array
    {
        $limpio = [];

        foreach ($argumentos as $clave => $valor) {
            if (is_string($clave) && in_array(mb_strtolower($clave), self::CLAVES_IDENTIDAD, true)) {
                continue;
            }

            $limpio[$clave] = is_array($valor) ? self::sinIdentidad($valor) : $valor;
        }

        return $limpio;
    }
}
