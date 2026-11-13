<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Acceso\StoreUsuarioRequest;
use App\Http\Requests\Acceso\UpdateUsuarioRequest;
use App\Models\Rol;
use App\Models\User;
use App\Services\Acceso\UsuarioAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioController extends Controller
{
    public function __construct(private readonly UsuarioAdminService $usuarios) {}

    public function index(): JsonResponse
    {
        $lista = User::with('rol')->orderBy('name')->get()->map(fn (User $u) => $this->presentar($u));

        return response()->json(['success' => true, 'data' => $lista]);
    }

    public function store(StoreUsuarioRequest $request): JsonResponse
    {
        $usuario = $this->usuarios->crear($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Usuario creado. Las credenciales se enviarán por correo.',
            'data' => [
                'usuario' => $this->presentar($usuario),
                'credenciales_estado' => $usuario->credenciales_estado,
            ],
        ], 201);
    }

    public function update(UpdateUsuarioRequest $request, User $usuario): JsonResponse
    {
        $actor = $request->user();

        if ($request->has('id_rol')) {
            $usuario = $this->usuarios->cambiarRol($usuario, Rol::findOrFail($request->integer('id_rol')), $actor);
        }

        if ($request->has('activo')) {
            $usuario = $this->usuarios->cambiarEstado($usuario, $request->boolean('activo'), $actor);
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente.',
            'data' => $this->presentar($usuario),
        ]);
    }

    public function reenviarCredenciales(Request $request, User $usuario): JsonResponse
    {
        $usuario = $this->usuarios->reenviarCredenciales($usuario, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Se enviarán nuevas credenciales por correo.',
            'data' => [
                'usuario' => $this->presentar($usuario),
                'credenciales_estado' => $usuario->credenciales_estado,
            ],
        ]);
    }

    /**
     * Solo campos públicos: nunca la contraseña ni el remember_token.
     *
     * @return array<string, mixed>
     */
    private function presentar(User $usuario): array
    {
        return [
            'id' => $usuario->id,
            'name' => $usuario->name,
            'email' => $usuario->email,
            'rol' => $usuario->rol
                ? ['id' => $usuario->rol->id, 'clave' => $usuario->rol->clave, 'nombre' => $usuario->rol->nombre]
                : null,
            'activo' => $usuario->activo,
            'debe_cambiar_password' => $usuario->debe_cambiar_password,
            'credenciales_estado' => $usuario->credenciales_estado,
            'credenciales_error' => $usuario->credenciales_error,
            'created_at' => $usuario->created_at?->toIso8601String(),
        ];
    }
}
