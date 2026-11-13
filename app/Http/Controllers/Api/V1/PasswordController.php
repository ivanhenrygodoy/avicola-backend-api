<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CambiarPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    /**
     * Cambia la contraseña del usuario autenticado (R11): apaga el aviso de primer ingreso
     * y revoca TODOS sus tokens, incluido el actual, así que debe volver a iniciar sesión.
     */
    public function cambiar(CambiarPasswordRequest $request): JsonResponse
    {
        $usuario = $request->user();

        $usuario->forceFill([
            'password' => $request->validated('password'),
            'debe_cambiar_password' => false,
        ])->save();

        $usuario->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Contraseña actualizada. Inicia sesión de nuevo con tu nueva contraseña.',
        ]);
    }

    /**
     * «Mantener esta contraseña» (R19): solo apaga la bandera del propio usuario autenticado.
     * No cambia ningún secreto ni pide la contraseña.
     */
    public function mantener(Request $request): JsonResponse
    {
        $request->user()->forceFill(['debe_cambiar_password' => false])->save();

        return response()->json([
            'success' => true,
            'message' => 'Se mantiene tu contraseña actual.',
        ]);
    }
}
