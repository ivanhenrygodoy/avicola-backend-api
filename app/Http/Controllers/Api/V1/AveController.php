<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Aves\StoreAveRequest;
use App\Http\Requests\Aves\TrasladarAveRequest;
use App\Http\Requests\Aves\UpdateAveRequest;
use App\Models\Ave;
use App\Models\CtlEspecie;
use App\Models\CtlProposito;
use App\Models\CtlSexo;
use App\Models\Division;
use App\Models\MovimientoAve;
use App\Services\Aves\TrasladarAveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AveController extends Controller
{
    /**
     * Retorna los catálogos requeridos para formularios de aves (ctl_especie, ctl_sexo, ctl_proposito).
     */
    public function catalogos(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'especies' => CtlEspecie::where('activa', true)->orderBy('id')->get(),
                'sexos' => CtlSexo::orderBy('id')->get(),
                'propositos' => CtlProposito::where('activa', true)->orderBy('id')->get(),
            ],
        ]);
    }

    /**
     * Listado paginado de aves con filtros y estadísticas agregadas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Ave::with(['division.casita', 'especie', 'sexo', 'proposito']);

        // Filtro por término de búsqueda (código o nombre)
        if ($request->filled('search')) {
            $search = '%' . mb_strtolower(trim($request->query('search'))) . '%';
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(codigo) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(nombre) LIKE ?', [$search]);
            });
        }

        // Filtro por ID de especie (catálogo ctl_especie)
        if ($request->filled('id_especie')) {
            $query->where('id_especie', $request->query('id_especie'));
        }

        // Filtro por ID de sexo (catálogo ctl_sexo)
        if ($request->filled('id_sexo')) {
            $query->where('id_sexo', $request->query('id_sexo'));
        }

        // Filtro por estado (por defecto 'activa' a menos que se especifique 'todos' o estado concreto)
        if ($request->filled('estado')) {
            if ($request->query('estado') !== 'todos') {
                $query->where('estado', $request->query('estado'));
            }
        } else {
            $query->where('estado', 'activa');
        }

        // Filtro por división
        if ($request->filled('id_division')) {
            $query->where('id_division', $request->query('id_division'));
        }

        // Filtro por casita
        if ($request->filled('id_casita')) {
            $idCasita = $request->query('id_casita');
            $query->whereHas('division', function ($q) use ($idCasita) {
                $q->where('id_casita', $idCasita);
            });
        }

        $perPage = (int) $request->query('per_page', 10);
        $aves = $query->orderBy('id', 'desc')->paginate($perPage);

        // Estadísticas agregadas globales
        $stats = [
            'total_aves' => Ave::count(),
            'activas_count' => Ave::where('estado', 'activa')->count(),
            'gallinas_count' => Ave::where('estado', 'activa')->whereHas('especie', fn($q) => $q->where('nombre', 'Gallina'))->count(),
            'chompipes_count' => Ave::where('estado', 'activa')->whereHas('especie', fn($q) => $q->where('nombre', 'Chompipe'))->count(),
            'patos_count' => Ave::where('estado', 'activa')->whereHas('especie', fn($q) => $q->where('nombre', 'Pato'))->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $aves->items(),
            'meta' => [
                'current_page' => $aves->currentPage(),
                'last_page' => $aves->lastPage(),
                'per_page' => $aves->perPage(),
                'total' => $aves->total(),
                'from' => $aves->firstItem(),
                'to' => $aves->lastItem(),
            ],
            'stats' => $stats,
        ]);
    }

    /**
     * Registrar una nueva ave con validación biológica si se asigna división.
     */
    public function store(StoreAveRequest $request, TrasladarAveService $service): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated, $service) {
            // Si se asigna división en el alta, validar división y regla biológica
            if (! empty($validated['id_division'])) {
                $division = Division::findOrFail($validated['id_division']);

                if (! $division->activa) {
                    throw ValidationException::withMessages([
                        'id_division' => ["La división seleccionada '{$division->nombre}' está inactiva."],
                    ]);
                }

                // Instancia temporal para validar compatibilidad biológica
                $tempAve = new Ave($validated);
                $service->validarCompatibilidadBiologica($tempAve, $division);
            }

            $ave = Ave::create($validated);

            // Registrar movimiento de ingreso inicial si tiene división
            if (! empty($validated['id_division'])) {
                MovimientoAve::create([
                    'id_ave' => $ave->id,
                    'id_division_origen' => null,
                    'id_division_destino' => $validated['id_division'],
                    'fecha' => $validated['fecha_ingreso'] ?: now(),
                    'motivo' => 'Ingreso inicial a la granja',
                    'observaciones' => $validated['observaciones'] ?? null,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Ave registrada exitosamente.',
                'data' => $ave->load(['division.casita', 'especie', 'sexo', 'proposito']),
            ], 201);
        });
    }

    /**
     * Consultar el detalle de un ave específica con su división y últimos movimientos.
     */
    public function show(Ave $ave): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $ave->load([
                'division.casita',
                'especie',
                'sexo',
                'proposito',
                'movimientos.divisionOrigen.casita',
                'movimientos.divisionDestino.casita',
            ]),
        ]);
    }

    /**
     * Actualizar los datos de un ave.
     */
    public function update(UpdateAveRequest $request, Ave $ave, TrasladarAveService $service): JsonResponse
    {
        $validated = $request->validated();

        return DB::transaction(function () use ($validated, $ave, $service) {
            $nuevaDivisionId = $validated['id_division'] ?? null;

            // Si cambió la división, usar el servicio de traslado para garantizar reglas
            if ($nuevaDivisionId && $nuevaDivisionId !== $ave->id_division) {
                $divisionDestino = Division::findOrFail($nuevaDivisionId);
                $service->ejecutar(
                    $ave,
                    $divisionDestino,
                    'Actualización de datos generales',
                    $validated['observaciones'] ?? null
                );
            }

            $ave->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Ave actualizada exitosamente.',
                'data' => $ave->fresh(['division.casita', 'especie', 'sexo', 'proposito']),
            ]);
        });
    }

    /**
     * Dar de baja o eliminar un ave.
     */
    public function destroy(Ave $ave): JsonResponse
    {
        // Si tiene movimientos históricos, se marca como salida en lugar de borrado físico
        if ($ave->movimientos()->count() > 1) {
            $ave->update([
                'estado' => 'salida',
                'id_division' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'El ave ha sido dada de baja (estado: salida) conservando su historial.',
            ]);
        }

        $ave->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ave eliminada correctamente.',
        ]);
    }

    /**
     * Trasladar un ave a otra división aplicando la regla biológica.
     */
    public function trasladar(TrasladarAveRequest $request, Ave $ave, TrasladarAveService $service): JsonResponse
    {
        $divisionDestino = Division::findOrFail($request->id_division_destino);

        $resultado = $service->ejecutar(
            $ave,
            $divisionDestino,
            $request->motivo,
            $request->observaciones,
            $request->fecha
        );

        return response()->json([
            'success' => true,
            'message' => "Ave '{$ave->codigo}' trasladada exitosamente a '{$divisionDestino->nombre}'.",
            'data' => $resultado,
        ]);
    }

    /**
     * Consultar el historial cronológico de movimientos de un ave.
     */
    public function movimientos(Ave $ave): JsonResponse
    {
        $movimientos = $ave->movimientos()
            ->with(['divisionOrigen.casita', 'divisionDestino.casita'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $movimientos,
        ]);
    }
}
