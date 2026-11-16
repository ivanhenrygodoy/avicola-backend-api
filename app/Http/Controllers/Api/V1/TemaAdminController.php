<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tema\UpdateComponenteTemaRequest;
use App\Models\ComponenteTema;
use App\Services\TemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemaAdminController extends Controller
{
    public function __construct(private readonly TemaService $temaService) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->temaService->listarComponentes(),
        ]);
    }

    public function show(ComponenteTema $componente): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->temaService->obtenerDetalleComponente($componente),
        ]);
    }

    public function update(UpdateComponenteTemaRequest $request, ComponenteTema $componente): JsonResponse
    {
        $actualizado = $this->temaService->actualizarComponente(
            $componente,
            $request->validated('modo'),
            $request->validated('aspectos')
        );

        return response()->json([
            'success' => true,
            'message' => "Apariencia de {$componente->nombre} actualizada correctamente.",
            'data' => $actualizado,
        ]);
    }

    public function restaurar(Request $request, ComponenteTema $componente): JsonResponse
    {
        $modo = $request->input('modo');
        if (! in_array($modo, ['claro', 'nocturno'], true)) {
            $modo = null;
        }

        $restaurado = $this->temaService->restaurarComponente($componente, $modo);

        return response()->json([
            'success' => true,
            'message' => "Valores predeterminados de {$componente->nombre} restaurados.",
            'data' => $restaurado,
        ]);
    }

    public function restaurarTodo(): JsonResponse
    {
        $this->temaService->restaurarTodo();

        return response()->json([
            'success' => true,
            'message' => 'Toda la paleta del sistema fue restaurada a los valores predeterminados.',
            'data' => $this->temaService->listarComponentes(),
        ]);
    }
}

