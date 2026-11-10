<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistente\ChatRequest;
use App\Services\Asistente\AsistenteOrquestadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class AsistenteController extends Controller
{
    /**
     * Procesa un turno de conversación en lenguaje natural mediante el Asistente de IA.
     */
    public function chat(ChatRequest $request, AsistenteOrquestadorService $orquestador): JsonResponse
    {
        $mensaje = (string) $request->input('mensaje');
        $historial = (array) $request->input('historial', []);

        // Contexto fijo anti-IDOR inyectado directamente desde la sesión autenticada
        $contextoFijo = [
            'id_usuario' => $request->user()?->id,
        ];

        try {
            $resultado = $orquestador->procesarConversacion(
                $mensaje,
                $historial,
                $contextoFijo,
                $request->input('uuid_conversacion')
            );

            return response()->json([
                'success' => true,
                'data' => $resultado,
            ]);
        } catch (Throwable $e) {
            Log::error('Error en servicio Asistente Virtual AviaCore: '.$e->getMessage(), [
                'usuario' => $request->user()?->id,
                'mensaje_preview' => mb_substr($mensaje, 0, 80),
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'El servicio de asistencia inteligente AviaCore está experimentando una pausa temporal o demora de conexión con el proveedor de IA. Por favor, intenta realizar tu consulta nuevamente en unos instantes.',
            ], 503);
        }
    }
}
