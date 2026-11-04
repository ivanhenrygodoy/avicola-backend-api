<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instalaciones\StoreCasitaRequest;
use App\Http\Requests\Instalaciones\UpdateCasitaRequest;
use App\Models\Casita;
use App\Models\CtlTipoInstalacion;
use App\Models\Division;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CasitaController extends Controller
{
    /**
     * Retorna el catálogo de tipos de instalación.
     */
    public function tipos(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CtlTipoInstalacion::where('activa', true)->orderBy('id')->get(),
        ]);
    }

    /**
     * Listar casitas con búsqueda, filtros, paginación dinámica y estadísticas globales.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Casita::with([
            'tipoInstalacion',
            'divisiones' => function ($q) {
                $q->orderBy('id', 'asc');
            },
        ]);

        // Filtro de búsqueda por texto (nombre o descripción)
        if ($request->filled('buscar')) {
            $buscar = trim($request->input('buscar'));
            $query->where(function ($q) use ($buscar) {
                $q->whereRaw('LOWER(nombre) LIKE ?', ['%' . mb_strtolower($buscar) . '%'])
                  ->orWhereRaw('LOWER(descripcion) LIKE ?', ['%' . mb_strtolower($buscar) . '%']);
            });
        }

        // Filtro por tipo de instalación (admite ID de catálogo o nombre)
        if ($request->filled('tipo')) {
            $tipo = $request->input('tipo');
            if (is_numeric($tipo)) {
                $query->where('id_tipo_instalacion', (int) $tipo);
            } else {
                $query->where(function ($q) use ($tipo) {
                    $q->where('tipo', $tipo)
                      ->orWhereHas('tipoInstalacion', fn($sq) => $sq->where('nombre', $tipo));
                });
            }
        }

        if ($request->filled('id_tipo_instalacion')) {
            $query->where('id_tipo_instalacion', (int) $request->input('id_tipo_instalacion'));
        }

        // Filtro por estado activo/inactivo
        if ($request->filled('activa')) {
            $activa = filter_var($request->input('activa'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($activa !== null) {
                $query->where('activa', $activa);
            }
        }

        // Paginación dinámica (por defecto 5 registros)
        $perPage = (int) $request->input('per_page', 5);
        if ($perPage <= 0 || $perPage > 100) {
            $perPage = 5;
        }

        $paginated = $query->orderBy('id', 'asc')->paginate($perPage);

        // Estadísticas agregadas globales
        $stats = [
            'total_casitas' => Casita::count(),
            'total_divisiones' => Division::count(),
            'divisiones_activas' => Division::where('activa', true)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Registrar una nueva casita.
     */
    public function store(StoreCasitaRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (!empty($data['id_tipo_instalacion']) && empty($data['tipo'])) {
            $tipoCat = CtlTipoInstalacion::find($data['id_tipo_instalacion']);
            if ($tipoCat) {
                $data['tipo'] = $tipoCat->nombre;
            }
        } elseif (!empty($data['tipo']) && empty($data['id_tipo_instalacion'])) {
            $tipoCat = CtlTipoInstalacion::where('nombre', $data['tipo'])->first();
            if ($tipoCat) {
                $data['id_tipo_instalacion'] = $tipoCat->id;
            }
        }

        $casita = Casita::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Casita creada exitosamente.',
            'data' => $casita->load(['divisiones', 'tipoInstalacion']),
        ], 201);
    }

    /**
     * Consultar detalle de una casita específica.
     */
    public function show(Casita $casita): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $casita->load(['divisiones', 'tipoInstalacion']),
        ]);
    }

    /**
     * Actualizar una casita.
     */
    public function update(UpdateCasitaRequest $request, Casita $casita): JsonResponse
    {
        $data = $request->validated();

        if (!empty($data['id_tipo_instalacion']) && empty($data['tipo'])) {
            $tipoCat = CtlTipoInstalacion::find($data['id_tipo_instalacion']);
            if ($tipoCat) {
                $data['tipo'] = $tipoCat->nombre;
            }
        } elseif (!empty($data['tipo']) && empty($data['id_tipo_instalacion'])) {
            $tipoCat = CtlTipoInstalacion::where('nombre', $data['tipo'])->first();
            if ($tipoCat) {
                $data['id_tipo_instalacion'] = $tipoCat->id;
            }
        }

        $casita->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Casita actualizada exitosamente.',
            'data' => $casita->load(['divisiones', 'tipoInstalacion']),
        ]);
    }

    /**
     * Alternar estado activa/inactiva de una casita.
     */
    public function toggleActiva(Casita $casita): JsonResponse
    {
        $casita->update([
            'activa' => ! $casita->activa,
        ]);

        return response()->json([
            'success' => true,
            'message' => $casita->activa ? 'Casita activada correctamente.' : 'Casita desactivada correctamente.',
            'data' => $casita->load('divisiones'),
        ]);
    }

    /**
     * Eliminar una casita (si no contiene divisiones).
     */
    public function destroy(Casita $casita): JsonResponse
    {
        if ($casita->divisiones()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede eliminar la casita porque tiene divisiones asociadas.',
            ], 422);
        }

        $casita->delete();

        return response()->json([
            'success' => true,
            'message' => 'Casita eliminada correctamente.',
        ]);
    }
}
