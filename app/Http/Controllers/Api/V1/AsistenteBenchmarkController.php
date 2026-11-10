<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Asistente\EstadisticasBenchmarkRequest;
use App\Http\Requests\Asistente\FiltroLogsAgenteRequest;
use App\Models\FuncionLogAgent;
use App\Services\Asistente\Benchmark\BenchmarkConsultaService;
use Illuminate\Http\JsonResponse;

/**
 * Auditoría del asistente AviaCore (Benchmark): estadísticas, listado de logs y detalle de un turno.
 */
class AsistenteBenchmarkController extends Controller
{
    public function __construct(private readonly BenchmarkConsultaService $consulta) {}

    public function estadisticas(EstadisticasBenchmarkRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->consulta->estadisticas((int) $request->input('dias', 7)),
        ]);
    }

    public function logs(FiltroLogsAgenteRequest $request): JsonResponse
    {
        $filtros = $request->validated();
        $logs = $this->consulta->logs($filtros, (int) ($filtros['per_page'] ?? 10));

        return response()->json([
            'success' => true,
            'data' => collect($logs->items())->map(fn (FuncionLogAgent $log) => $this->consulta->resumenLog($log))->all(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'from' => $logs->firstItem() ?? 0,
                'to' => $logs->lastItem() ?? 0,
            ],
            'stats' => $this->consulta->resumenDelFiltro($filtros),
        ]);
    }

    public function show(FuncionLogAgent $log): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->consulta->detalle($log),
        ]);
    }
}
