<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Aves\StoreRegistroPesoRequest;
use App\Models\Ave;
use App\Models\RegistroPeso;
use Illuminate\Http\JsonResponse;

class PesoAveController extends Controller
{
    /**
     * Listado del historial de pesos de un ave.
     */
    public function index(Ave $ave): JsonResponse
    {
        $pesos = $ave->pesos()
            ->with('usuario')
            ->orderBy('fecha', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $pesos,
        ]);
    }

    /**
     * Registrar un nuevo pesaje para el ave.
     */
    public function store(StoreRegistroPesoRequest $request, Ave $ave): JsonResponse
    {
        $data = $request->validated();
        $data['id_ave'] = $ave->id;
        $data['id_usuario'] = $request->user()?->id;

        $registro = RegistroPeso::create($data);
        $registro->load('usuario');

        // Refrescar el ave para obtener el peso_actual_kg actualizado por el observer
        $ave->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Pesaje registrado exitosamente.',
            'data' => $registro,
            'ave' => [
                'id' => $ave->id,
                'peso_actual_kg' => $ave->peso_actual_kg,
            ],
        ], 201);
    }

    /**
     * Eliminar un registro de pesaje.
     */
    public function destroy(RegistroPeso $peso): JsonResponse
    {
        $ave = $peso->ave;
        $peso->delete();
        $ave?->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Registro de pesaje eliminado exitosamente.',
            'ave' => $ave ? [
                'id' => $ave->id,
                'peso_actual_kg' => $ave->peso_actual_kg,
            ] : null,
        ]);
    }
}

