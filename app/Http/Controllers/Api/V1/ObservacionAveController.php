<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Aves\StoreObservacionAveRequest;
use App\Models\Ave;
use App\Models\ObservacionAve;
use Illuminate\Http\JsonResponse;

class ObservacionAveController extends Controller
{
    /**
     * Listado de observaciones y eventos de bitácora sanitaria de un ave.
     */
    public function index(Ave $ave): JsonResponse
    {
        $observaciones = $ave->observaciones()
            ->with('usuario')
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $observaciones,
        ]);
    }

    /**
     * Registrar una observación o evento sanitario para el ave.
     */
    public function store(StoreObservacionAveRequest $request, Ave $ave): JsonResponse
    {
        $data = $request->validated();
        $data['id_ave'] = $ave->id;
        $data['id_usuario'] = $request->user()?->id;

        $observacion = ObservacionAve::create($data);
        $observacion->load('usuario');

        return response()->json([
            'success' => true,
            'message' => 'Evento de bitácora registrado exitosamente.',
            'data' => $observacion,
        ], 201);
    }

    /**
     * Eliminar un registro de bitácora.
     */
    public function destroy(ObservacionAve $observacion): JsonResponse
    {
        $observacion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Registro de bitácora eliminado exitosamente.',
        ]);
    }
}

