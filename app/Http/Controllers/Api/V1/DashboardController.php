<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Dashboard\ActividadDashboardRequest;
use App\Http\Requests\Dashboard\ResumenDashboardRequest;
use App\Services\Dashboard\ActividadRecienteService;
use App\Services\Dashboard\DashboardResumenService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Resumen analítico consolidado de la granja.
     */
    public function resumen(ResumenDashboardRequest $request, DashboardResumenService $service): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $service->resumen($request->integer('dias', 7)),
        ]);
    }

    /**
     * Actividad reciente paginada (traslados, postura, gastos y observaciones).
     */
    public function actividad(ActividadDashboardRequest $request, ActividadRecienteService $service): JsonResponse
    {
        $pagina = $service->paginar(
            $request->integer('page', 1),
            $request->integer('per_page', 5),
        );

        return response()->json([
            'success' => true,
            'data' => $pagina->items(),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page' => $pagina->lastPage(),
                'per_page' => $pagina->perPage(),
                'total' => $pagina->total(),
                'from' => $pagina->firstItem() ?? 0,
                'to' => $pagina->lastItem() ?? 0,
            ],
        ]);
    }
}
