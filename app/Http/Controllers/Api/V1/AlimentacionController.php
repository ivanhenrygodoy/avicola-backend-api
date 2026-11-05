<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Alimentacion\StoreRegistroAlimentacionRequest;
use App\Http\Requests\Alimentacion\UpdateRegistroAlimentacionRequest;
use App\Models\CtlTipoAlimento;
use App\Models\RegistroAlimentacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlimentacionController extends Controller
{
    /**
     * Retorna el catálogo de tipos de alimento disponibles.
     */
    public function tipos(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CtlTipoAlimento::where('activo', true)->orderBy('nombre')->get(),
        ]);
    }

    /**
     * Listado paginado de registros de alimentación con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RegistroAlimentacion::with(['division.casita', 'tipoAlimento', 'usuario']);

        // Filtro por término de búsqueda (observaciones, tipo de alimento, corral o casita)
        if ($request->filled('search')) {
            $search = '%' . mb_strtolower(trim($request->query('search'))) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(observaciones) LIKE ?', [$search])
                    ->orWhereHas('tipoAlimento', function ($qt) use ($search) {
                        $qt->whereRaw('LOWER(nombre) LIKE ?', [$search]);
                    })
                    ->orWhereHas('division', function ($qd) use ($search) {
                        $qd->whereRaw('LOWER(nombre) LIKE ?', [$search])
                            ->orWhereHas('casita', function ($qc) use ($search) {
                                $qc->whereRaw('LOWER(nombre) LIKE ?', [$search]);
                            });
                    });
            });
        }

        // Filtro por división
        if ($request->filled('id_division')) {
            $query->where('id_division', $request->query('id_division'));
        }

        // Filtro por tipo de alimento
        if ($request->filled('id_tipo_alimento')) {
            $query->where('id_tipo_alimento', $request->query('id_tipo_alimento'));
        }

        // Filtro por rango de fechas
        if ($request->filled('fecha_inicio')) {
            $query->whereDate('fecha', '>=', $request->query('fecha_inicio'));
        }
        if ($request->filled('fecha_fin')) {
            $query->whereDate('fecha', '<=', $request->query('fecha_fin'));
        }

        // Ordenamiento por defecto: más recientes primero
        $query->orderBy('fecha', 'desc')->orderBy('id', 'desc');

        // Cálculo de estadísticas globales / del filtro actual
        $statsQuery = clone $query;
        $totalKilos = (float) $statsQuery->sum('cantidad');
        $totalCosto = (float) $statsQuery->sum('costo_total');
        $totalRegistros = $statsQuery->count();

        $perPage = (int) $request->query('per_page', 10);
        $registros = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $registros->items(),
            'meta' => [
                'current_page' => $registros->currentPage(),
                'last_page' => $registros->lastPage(),
                'per_page' => $registros->perPage(),
                'total' => $registros->total(),
                'from' => $registros->firstItem() ?? 0,
                'to' => $registros->lastItem() ?? 0,
            ],
            'stats' => [
                'total_kilos' => round($totalKilos, 2),
                'total_costo' => round($totalCosto, 2),
                'total_registros' => $totalRegistros,
            ],
        ]);
    }

    /**
     * Registrar suministro de alimento.
     */
    public function store(StoreRegistroAlimentacionRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['id_usuario'] = $request->user()?->id;

        $registro = RegistroAlimentacion::create($data);
        $registro->load(['division.casita', 'tipoAlimento', 'usuario']);

        return response()->json([
            'success' => true,
            'message' => 'Ración de alimento registrada exitosamente.',
            'data' => $registro,
        ], 201);
    }

    /**
     * Ver detalle de un registro de alimentación.
     */
    public function show(RegistroAlimentacion $alimentacion): JsonResponse
    {
        $alimentacion->load(['division.casita', 'tipoAlimento', 'usuario']);

        return response()->json([
            'success' => true,
            'data' => $alimentacion,
        ]);
    }

    /**
     * Actualizar registro de alimentación.
     */
    public function update(UpdateRegistroAlimentacionRequest $request, RegistroAlimentacion $alimentacion): JsonResponse
    {
        $alimentacion->update($request->validated());
        $alimentacion->load(['division.casita', 'tipoAlimento', 'usuario']);

        return response()->json([
            'success' => true,
            'message' => 'Registro de alimentación actualizado exitosamente.',
            'data' => $alimentacion,
        ]);
    }

    /**
     * Eliminar registro de alimentación.
     */
    public function destroy(RegistroAlimentacion $alimentacion): JsonResponse
    {
        $alimentacion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Registro de alimentación eliminado exitosamente.',
        ]);
    }
}

