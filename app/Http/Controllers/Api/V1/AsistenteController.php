<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistente\ChatRequest;
use App\Services\Asistente\AsistenteOrquestadorService;
use Illuminate\Http\JsonResponse;
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
            $resultado = $orquestador->procesarConversacion($mensaje, $historial, $contextoFijo);

            return response()->json([
                'success' => true,
                'data' => $resultado,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'El servicio de IA no está disponible temporalmente. '.$e->getMessage(),
            ], 503);
        }
    }
}

