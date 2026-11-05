<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Huevos\StoreRegistroHuevoRequest;
use App\Http\Requests\Huevos\UpdateRegistroHuevoRequest;
use App\Models\RegistroHuevo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HuevoController extends Controller
{
    /**
     * Listado paginado de registros de recolección de postura con filtros y estadísticas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = RegistroHuevo::with(['division.casita', 'usuario']);

        // Filtro por término de búsqueda (observaciones, corral o casita)
        if ($request->filled('search')) {
            $search = '%' . mb_strtolower(trim($request->query('search'))) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(observaciones) LIKE ?', [$search])
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
        $totalRecolectados = (int) $statsQuery->sum('cantidad_recolectada');
        $totalRotos = (int) $statsQuery->sum('cantidad_rotos');
        $totalAprovechables = (int) $statsQuery->sum('cantidad_aprovechables');
        $totalRegistros = $statsQuery->count();
        $porcentajeRotos = $totalRecolectados > 0
            ? round(($totalRotos / $totalRecolectados) * 100, 1)
            : 0;

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
                'total_recolectados' => $totalRecolectados,
                'total_rotos' => $totalRotos,
                'total_aprovechables' => $totalAprovechables,
                'porcentaje_rotura' => $porcentajeRotos,
                'total_registros' => $totalRegistros,
            ],
        ]);
    }

    /**
     * Registrar recolección diaria de huevos.
     */
    public function store(StoreRegistroHuevoRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['id_usuario'] = $request->user()?->id;

        $registro = RegistroHuevo::create($data);
        $registro->load(['division.casita', 'usuario']);

        return response()->json([
            'success' => true,
            'message' => 'Postura diaria registrada exitosamente.',
            'data' => $registro,
        ], 201);
    }

    /**
     * Ver detalle de un registro de postura.
     */
    public function show(RegistroHuevo $huevo): JsonResponse
    {
        $huevo->load(['division.casita', 'usuario']);

        return response()->json([
            'success' => true,
            'data' => $huevo,
        ]);
    }

    /**
     * Actualizar registro de postura.
     */
    public function update(UpdateRegistroHuevoRequest $request, RegistroHuevo $huevo): JsonResponse
    {
        $huevo->update($request->validated());
        $huevo->load(['division.casita', 'usuario']);

        return response()->json([
            'success' => true,
            'message' => 'Registro de postura actualizado exitosamente.',
            'data' => $huevo,
        ]);
    }

    /**
     * Eliminar registro de postura.
     */
    public function destroy(RegistroHuevo $huevo): JsonResponse
    {
        $huevo->delete();

        return response()->json([
            'success' => true,
            'message' => 'Registro de postura eliminado exitosamente.',
        ]);
    }
}

