<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Acceso\PermisosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly PermisosService $permisos) {}

    /**
     * Iniciar sesión y emitir token Bearer.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $request->ensureIsNotRateLimited();

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas no son correctas.'],
            ]);
        }

        // Solo se revela que la cuenta está desactivada cuando la contraseña es correcta,
        // para no filtrar qué correos existen.
        if (! $user->activo) {
            $request->hitRateLimiter();

            throw ValidationException::withMessages([
                'email' => ['Tu cuenta está desactivada. Contacta al administrador.'],
            ]);
        }

        $request->clearRateLimiter();

        // Eliminar tokens previos si se desea sesión única o permitir múltiples
        $token = $user->createToken('avicola_api_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión exitoso.',
            'data' => [
                'token' => $token,
                'user' => $this->permisos->datosDeSesion($user),
            ],
        ]);
    }

    /**
     * Cerrar sesión y destruir el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * Obtener los datos del usuario autenticado actual.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->permisos->datosDeSesion($user),
        ]);
    }
}
