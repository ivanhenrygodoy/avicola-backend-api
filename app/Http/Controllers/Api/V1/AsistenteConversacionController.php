<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistente\RenombrarConversacionRequest;
use App\Models\ConversacionAsistente;
use App\Services\Asistente\Conversacion\ConversacionAsistenteService;
use App\Services\Asistente\Conversacion\TituloConversacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Historial de conversaciones del usuario con el asistente: listar, abrir, renombrar y eliminar del historial.
 *
 * Todo opera solo sobre las conversaciones activas del usuario autenticado. Una conversación ajena,
 * inexistente, eliminada o con UUID inválido responde el mismo 404, para que no se pueda enumerar.
 */
class AsistenteConversacionController extends Controller
{
    public function __construct(private readonly ConversacionAsistenteService $conversaciones) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->conversaciones->listar((int) $request->user()->id),
        ]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $conversacion = $this->propia($request, $uuid);
        if ($conversacion === null) {
            return $this->noEncontrada();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'uuid' => $conversacion->uuid,
                'nombre' => $this->conversaciones->nombreVisible($conversacion),
                'mensajes' => $this->conversaciones->mensajes($conversacion),
            ],
        ]);
    }

    public function update(RenombrarConversacionRequest $request, string $uuid): JsonResponse
    {
        $conversacion = $this->propia($request, $uuid);
        if ($conversacion === null) {
            return $this->noEncontrada();
        }

        $this->conversaciones->renombrar($conversacion, (string) $request->validated('nombre'));

        return response()->json([
            'success' => true,
            'message' => 'Conversación renombrada.',
            'data' => [
                'uuid' => $conversacion->uuid,
                'nombre' => $this->conversaciones->nombreVisible($conversacion),
            ],
        ]);
    }

    /**
     * Genera (una sola vez) el nombre automático de la conversación. Si ya tiene nombre lo devuelve sin
     * llamar al modelo. Nunca falla por culpa del proveedor: el servicio siempre cae en un respaldo.
     */
    public function titulo(Request $request, string $uuid, TituloConversacionService $titulos): JsonResponse
    {
        $conversacion = $this->propia($request, $uuid);
        if ($conversacion === null) {
            return $this->noEncontrada();
        }

        $titulos->generar($conversacion);

        return response()->json([
            'success' => true,
            'data' => [
                'nombre' => $this->conversaciones->nombreVisible($conversacion->fresh()),
            ],
        ]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $conversacion = $this->propia($request, $uuid);
        if ($conversacion === null) {
            return $this->noEncontrada();
        }

        $this->conversaciones->eliminar($conversacion);

        return response()->json([
            'success' => true,
            'message' => 'Conversación eliminada del historial.',
        ]);
    }

    private function propia(Request $request, string $uuid): ?ConversacionAsistente
    {
        return $this->conversaciones->buscar((int) $request->user()->id, $uuid);
    }

    private function noEncontrada(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Conversación no encontrada.',
        ], 404);
    }
}
