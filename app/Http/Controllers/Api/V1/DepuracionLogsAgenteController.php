<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistente\DepuracionLogsAgenteRequest;
use App\Services\Asistente\Benchmark\DepuracionLogsAgenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint de depuración manual del histórico del Benchmark AviaCore.
 * Expone la vista previa y el borrado seguro por lotes.
 */
class DepuracionLogsAgenteController extends Controller
{
    public function __construct(private readonly DepuracionLogsAgenteService $servicio) {}

    public function vistaPrevia(DepuracionLogsAgenteRequest $request): JsonResponse
    {
        $meses = (int) $request->input('meses');

        return response()->json([
            'success' => true,
            'data' => $this->servicio->vistaPrevia($meses),
        ]);
    }

    public function destroy(DepuracionLogsAgenteRequest $request): JsonResponse
    {
        $meses = (int) $request->input('meses');
        $data = $this->servicio->depurar($meses);

        $usuario = $request->user();
        $idUsuario = $usuario?->id_usuario ?? $usuario?->id;

        Log::warning('[AviaCore] Limpieza de histórico del Benchmark', [
            'id_usuario' => $idUsuario,
            'meses' => $meses,
            'corte' => $data['corte'],
            'turnos' => $data['turnos'],
            'tools' => $data['tools'],
        ]);

        $mensaje = $data['turnos'] > 0
            ? "Se eliminaron {$data['turnos']} interacciones del historial."
            : "No había interacciones anteriores a {$data['corte']}.";

        return response()->json([
            'success' => true,
            'message' => $mensaje,
            'data' => $data,
        ]);
    }
}
