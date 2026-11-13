<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Acceso\SincronizarPermisosRequest;
use App\Models\Rol;
use App\Services\Acceso\PermisosService;
use Illuminate\Http\JsonResponse;

class PermisoController extends Controller
{
    public function __construct(private readonly PermisosService $permisos) {}

    /**
     * Matriz rol × módulo (solo superAdmin).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->permisos->matriz(),
        ]);
    }

    /**
     * Otorga o retira los módulos opcionales de un rol; los módulos base se conservan.
     */
    public function update(SincronizarPermisosRequest $request, Rol $rol): JsonResponse
    {
        $this->permisos->sincronizar($rol, $request->validated('modulos'));

        return response()->json([
            'success' => true,
            'message' => 'Permisos actualizados correctamente.',
            'data' => $this->permisos->matriz(),
        ]);
    }
}
