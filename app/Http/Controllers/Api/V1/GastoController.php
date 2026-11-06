<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gastos\StoreGastoRequest;
use App\Http\Requests\Gastos\UpdateGastoRequest;
use App\Models\CtlCategoriaGasto;
use App\Models\Gasto;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GastoController extends Controller
{
    /**
     * Catálogo de categorías de gastos.
     */
    public function categorias(): JsonResponse
    {
        $categorias = CtlCategoriaGasto::where('activa', true)
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $categorias,
        ]);
    }

    /**
     * Listado paginado de gastos operativos con filtros y estadísticas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Gasto::with(['categoria', 'division.casita', 'usuario']);

        // Búsqueda general
        if ($request->filled('search')) {
            $search = '%' . mb_strtolower(trim($request->query('search'))) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(concepto) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(proveedor) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(observaciones) LIKE ?', [$search])
                    ->orWhereHas('categoria', function ($qc) use ($search) {
                        $qc->whereRaw('LOWER(nombre) LIKE ?', [$search]);
                    })
                    ->orWhereHas('division', function ($qd) use ($search) {
                        $qd->whereRaw('LOWER(nombre) LIKE ?', [$search])
                            ->orWhereHas('casita', function ($qcas) use ($search) {
                                $qcas->whereRaw('LOWER(nombre) LIKE ?', [$search]);
                            });
                    });
            });
        }

        // Filtro por categoría
        if ($request->filled('id_categoria_gasto')) {
            $query->where('id_categoria_gasto', $request->query('id_categoria_gasto'));
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

        // Ordenamiento por fecha desc
        $query->orderBy('fecha', 'desc')->orderBy('id', 'desc');

        // Estadísticas
        $statsQuery = clone $query;
        $totalGastos = (float) $statsQuery->sum('monto');
        $totalRegistros = $statsQuery->count();

        // Gasto del mes actual (en todo el sistema, para KPI representativo)
        $inicioMes = Carbon::now()->startOfMonth()->toDateString();
        $finMes = Carbon::now()->endOfMonth()->toDateString();
        $totalMesActual = (float) Gasto::whereDate('fecha', '>=', $inicioMes)
            ->whereDate('fecha', '<=', $finMes)
            ->sum('monto');

        $perPage = (int) $request->query('per_page', 10);
        $gastos = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $gastos->items(),
            'meta' => [
                'current_page' => $gastos->currentPage(),
                'last_page' => $gastos->lastPage(),
                'per_page' => $gastos->perPage(),
                'total' => $gastos->total(),
                'from' => $gastos->firstItem() ?? 0,
                'to' => $gastos->lastItem() ?? 0,
            ],
            'stats' => [
                'total_gastos' => round($totalGastos, 2),
                'total_mes_actual' => round($totalMesActual, 2),
                'total_registros' => $totalRegistros,
            ],
        ]);
    }

    /**
     * Registrar un nuevo gasto operativo.
     */
    public function store(StoreGastoRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['id_usuario'] = $request->user()?->id;

        $gasto = Gasto::create($data);
        $gasto->load(['categoria', 'division.casita', 'usuario']);

        return response()->json([
            'success' => true,
            'message' => 'Gasto registrado exitosamente.',
            'data' => $gasto,
        ], 201);
    }

    /**
     * Consultar detalle de un gasto.
     */
    public function show(Gasto $gasto): JsonResponse
    {
        $gasto->load(['categoria', 'division.casita', 'usuario']);

        return response()->json([
            'success' => true,
            'data' => $gasto,
        ]);
    }

    /**
     * Actualizar información de un gasto.
     */
    public function update(UpdateGastoRequest $request, Gasto $gasto): JsonResponse
    {
        $data = $request->validated();
        $gasto->update($data);
        $gasto->load(['categoria', 'division.casita', 'usuario']);

        return response()->json([
            'success' => true,
            'message' => 'Gasto actualizado exitosamente.',
            'data' => $gasto,
        ]);
    }

    /**
     * Eliminar un gasto.
     */
    public function destroy(Gasto $gasto): JsonResponse
    {
        $gasto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Gasto eliminado exitosamente.',
        ]);
    }
}

