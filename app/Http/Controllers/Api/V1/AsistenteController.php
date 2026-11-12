<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistente\ChatRequest;
use App\Models\ConversacionAsistente;
use App\Services\Asistente\AsistenteOrquestadorService;
use App\Services\Asistente\Conversacion\ConversacionAsistenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AsistenteController extends Controller
{
    private const MENSAJE_ERROR_PROVEEDOR = 'El servicio de asistencia inteligente AviaCore está experimentando una pausa temporal o demora de conexión con el proveedor de IA. Por favor, intenta realizar tu consulta nuevamente en unos instantes.';

    /**
     * Procesa un turno de conversación en lenguaje natural mediante el Asistente de IA.
     *
     * El historial lo arma el servidor desde la conversación guardada del usuario (el navegador solo
     * indica cuál retomar con su UUID, que se valida contra el usuario autenticado). La capa de
     * conversación nunca debe romper el chat: si falla, se registra y el turno se responde igual.
     */
    public function chat(ChatRequest $request, AsistenteOrquestadorService $orquestador, ConversacionAsistenteService $conversaciones): JsonResponse
    {
        $mensaje = (string) $request->input('mensaje');
        $idUsuario = (int) $request->user()?->id;

        // Contexto fijo anti-IDOR inyectado directamente desde la sesión autenticada
        $contextoFijo = [
            'id_usuario' => $idUsuario,
        ];

        $conversacion = $this->resolverConversacion($conversaciones, $idUsuario, $request->input('uuid_conversacion'));
        $contexto = $this->contextoDe($conversaciones, $conversacion);
        $uuidAuditoria = $conversacion ? $conversaciones->uuidAuditoria($conversacion) : null;

        try {
            $resultado = $orquestador->procesarConversacion($mensaje, $contexto, $contextoFijo, $uuidAuditoria);
        } catch (Throwable $e) {
            Log::error('Error en servicio Asistente Virtual AviaCore: '.$e->getMessage(), [
                'usuario' => $idUsuario,
                'mensaje_preview' => mb_substr($mensaje, 0, 80),
                'exception' => $e,
            ]);

            $this->guardarTurno($conversaciones, $conversacion, $mensaje, self::MENSAJE_ERROR_PROVEEDOR, 'error');

            return response()->json([
                'success' => false,
                'message' => self::MENSAJE_ERROR_PROVEEDOR,
            ], 503);
        }

        $tipo = (string) ($resultado['resultado'] ?? 'exitoso');
        $this->guardarTurno(
            $conversaciones,
            $conversacion,
            $mensaje,
            (string) $resultado['respuesta'],
            $tipo,
            (array) $resultado['herramientas_utilizadas'],
            (array) $resultado['sugerencias_modulos'],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'respuesta' => $resultado['respuesta'],
                'herramientas_utilizadas' => $resultado['herramientas_utilizadas'],
                'sugerencias_modulos' => $resultado['sugerencias_modulos'],
                'uuid_conversacion' => $conversacion?->uuid,
                // El nombre automático se pide aparte (sin sumar latencia al chat) tras el primer turno exitoso.
                'requiere_titulo' => $conversacion !== null
                    && $tipo === 'exitoso'
                    && $conversacion->nombre_origen === ConversacionAsistente::NOMBRE_PENDIENTE,
            ],
        ]);
    }

    private function resolverConversacion(ConversacionAsistenteService $conversaciones, int $idUsuario, mixed $uuid): ?ConversacionAsistente
    {
        try {
            return $conversaciones->obtenerOCrear($idUsuario, is_string($uuid) ? $uuid : null);
        } catch (Throwable $e) {
            Log::error('[AviaCore] No se pudo resolver la conversación del asistente: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contextoDe(ConversacionAsistenteService $conversaciones, ?ConversacionAsistente $conversacion): array
    {
        if ($conversacion === null) {
            return [];
        }

        try {
            return $conversaciones->contexto($conversacion);
        } catch (Throwable $e) {
            Log::error('[AviaCore] No se pudo armar el contexto de la conversación: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @param  array<int, string>  $herramientas
     * @param  array<int, array<string, mixed>>  $sugerencias
     */
    private function guardarTurno(
        ConversacionAsistenteService $conversaciones,
        ?ConversacionAsistente $conversacion,
        string $mensaje,
        string $respuesta,
        string $tipo,
        array $herramientas = [],
        array $sugerencias = [],
    ): void {
        if ($conversacion === null) {
            return;
        }

        try {
            $conversaciones->registrarTurno($conversacion, $mensaje, $respuesta, $tipo, $herramientas, $sugerencias);
        } catch (Throwable $e) {
            Log::error('[AviaCore] No se pudo guardar el turno de la conversación: '.$e->getMessage());
        }
    }
}
