<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege un grupo de rutas por módulo: `modulo:<clave>` (R3).
 *
 * Responde 403 si el usuario no tiene el módulo, si no tiene rol o si está desactivado
 * (Ruling 6). El superAdmin pasa siempre por regla (R4). Las claves permitidas se
 * calculan una sola vez por petición.
 */
class EnsureModuloPermitido
{
    public const MENSAJE = 'No tienes permiso para acceder a este módulo.';

    public function handle(Request $request, Closure $next, string $clave): Response
    {
        $usuario = $request->user();

        if ($usuario === null) {
            abort(401, 'No autenticado.');
        }

        $claves = $request->attributes->get('modulos_permitidos');

        if ($claves === null) {
            $claves = $usuario->clavesPermitidas();
            $request->attributes->set('modulos_permitidos', $claves);
        }

        if (! in_array($clave, $claves, true)) {
            return response()->json([
                'success' => false,
                'message' => self::MENSAJE,
            ], 403);
        }

        return $next($request);
    }
}
