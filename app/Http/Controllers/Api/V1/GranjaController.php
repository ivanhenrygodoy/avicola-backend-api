<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Granja;
use Illuminate\Http\JsonResponse;

class GranjaController extends Controller
{
    /**
     * Listado de granjas disponibles (para elegir a cuál pertenece una casita).
     */
    public function index(): JsonResponse
    {
        $granjas = Granja::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'descripcion']);

        return response()->json([
            'success' => true,
            'data' => $granjas,
        ]);
    }
}
