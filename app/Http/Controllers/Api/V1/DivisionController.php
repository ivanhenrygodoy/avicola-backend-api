<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Instalaciones\StoreDivisionRequest;
use App\Http\Requests\Instalaciones\UpdateDivisionRequest;
use App\Models\Division;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DivisionController extends Controller
{
    /**
     * Listar divisiones (opcionalmente filtradas por casita).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Division::with('casita');

        if ($request->has('id_casita')) {
            $query->where('id_casita', $request->query('id_casita'));
        }

        if ($request->has('activa')) {
            $query->where('activa', filter_var($request->query('activa'), FILTER_VALIDATE_BOOLEAN));
        }

        $divisiones = $query->orderBy('id', 'asc')->get();

        return response()->json([
            'success' => true,
            'data' => $divisiones,
        ]);
    }

    /**
     * Registrar una nueva división en una casita.
     */
    public function store(StoreDivisionRequest $request): JsonResponse
    {
        $division = Division::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'División creada exitosamente.',
            'data' => $division->load('casita'),
        ], 201);
    }

    /**
     * Consultar una división específica.
     */
    public function show(Division $division): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $division->load('casita'),
        ]);
    }

    /**
     * Actualizar datos de una división.
     */
    public function update(UpdateDivisionRequest $request, Division $division): JsonResponse
    {
        $division->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'División actualizada exitosamente.',
            'data' => $division->load('casita'),
        ]);
    }

    /**
     * Alternar estado activa/inactiva de una división.
     */
    public function toggleActiva(Division $division): JsonResponse
    {
        $division->update([
            'activa' => ! $division->activa,
        ]);

        return response()->json([
            'success' => true,
            'message' => $division->activa ? 'División activada correctamente.' : 'División desactivada correctamente.',
            'data' => $division,
        ]);
    }

    /**
     * Eliminar una división.
     */
    public function destroy(Division $division): JsonResponse
    {
        $division->delete();

        return response()->json([
            'success' => true,
            'message' => 'División eliminada correctamente.',
        ]);
    }
}

