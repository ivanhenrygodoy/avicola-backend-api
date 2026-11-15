<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tema\UpdatePreferenciaTemaRequest;
use App\Services\TemaService;
use Illuminate\Http\JsonResponse;

class TemaPublicoController extends Controller
{
    public function __construct(private readonly TemaService $temaService) {}

    /**
     * Paleta activa del sistema (claro y nocturno) para aplicar variables CSS al iniciar la SPA.
     */
    public function activo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->temaService->obtenerTemaActivo(),
        ]);
    }

    /**
     * Guarda la preferencia personal de modo claro/nocturno del usuario autenticado.
     */
    public function actualizarPreferencia(UpdatePreferenciaTemaRequest $request): JsonResponse
    {
        $modo = $this->temaService->actualizarModoUsuario(
            $request->user(),
            $request->validated('modo_tema')
        );

        return response()->json([
            'success' => true,
            'message' => 'Preferencia de tema actualizada.',
            'data' => ['modo_tema' => $modo],
        ]);
    }
}

