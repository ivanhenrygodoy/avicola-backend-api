<?php

namespace App\Services\Acceso;

use App\Jobs\EnviarCredencialesIniciales;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Administración de usuarios por el superAdmin: alta, rol, estado y reenvío de credenciales.
 */
class UsuarioAdminService
{
    /**
     * Crea el usuario con una clave aleatoria que nadie conoce y encola el envío (R21):
     * la temporal real la genera el job, así nunca viaja por la cola.
     *
     * @param  array{name: string, email: string, id_rol: int}  $datos
     */
    public function crear(array $datos): User
    {
        $usuario = User::create([
            'name' => $datos['name'],
            'email' => $datos['email'],
            'id_rol' => $datos['id_rol'],
            'password' => Str::random(40),
            'activo' => true,
            'debe_cambiar_password' => true,
            'credenciales_estado' => 'pendiente',
        ]);

        EnviarCredencialesIniciales::dispatch($usuario->id);

        return $usuario->load('rol');
    }

    public function cambiarRol(User $objetivo, Rol $rol, User $actor): User
    {
        if ($actor->is($objetivo)) {
            throw ValidationException::withMessages([
                'id_rol' => ['No puedes cambiar tu propio rol.'],
            ]);
        }

        if ($objetivo->id_rol === $rol->id) {
            return $objetivo->load('rol');
        }

        // Contar y escribir en la misma transacción, con los superAdmin activos bloqueados: dos
        // superAdmin que se degradan a la vez no pueden pasar ambos la comprobación.
        return DB::transaction(function () use ($objetivo, $rol) {
            $objetivo->refresh();

            if (! $rol->es_super && $this->esUltimoSuperAdminActivo($objetivo)) {
                throw ValidationException::withMessages([
                    'id_rol' => ['Debe quedar al menos un super administrador activo.'],
                ]);
            }

            $objetivo->update(['id_rol' => $rol->id]);
            // Se cierran sus sesiones para que menú y API queden alineados con el nuevo rol (R16).
            $objetivo->tokens()->delete();

            return $objetivo->load('rol');
        });
    }

    public function cambiarEstado(User $objetivo, bool $activo, User $actor): User
    {
        if (! $activo && $actor->is($objetivo)) {
            throw ValidationException::withMessages([
                'activo' => ['No puedes desactivar tu propia cuenta.'],
            ]);
        }

        // Misma defensa que en `cambiarRol`: contar y escribir bajo bloqueo, en una sola transacción.
        return DB::transaction(function () use ($objetivo, $activo) {
            $objetivo->refresh();

            if (! $activo && $this->esUltimoSuperAdminActivo($objetivo)) {
                throw ValidationException::withMessages([
                    'activo' => ['Debe quedar al menos un super administrador activo.'],
                ]);
            }

            $objetivo->update(['activo' => $activo]);

            if (! $activo) {
                $objetivo->tokens()->delete();
            }

            return $objetivo->load('rol');
        });
    }

    /**
     * Invalida la clave vigente y vuelve a encolar el envío (genera otra temporal).
     */
    public function reenviarCredenciales(User $objetivo, User $actor): User
    {
        if ($actor->is($objetivo)) {
            throw ValidationException::withMessages([
                'usuario' => ['No puedes reenviarte credenciales a ti mismo: cambia tu contraseña desde tu cuenta.'],
            ]);
        }

        if (! $objetivo->activo) {
            throw ValidationException::withMessages([
                'usuario' => ['No se pueden reenviar credenciales a un usuario desactivado.'],
            ]);
        }

        $objetivo->forceFill([
            'password' => Str::random(40),
            'debe_cambiar_password' => true,
            'credenciales_estado' => 'pendiente',
            'credenciales_error' => null,
        ])->save();
        $objetivo->tokens()->delete();

        EnviarCredencialesIniciales::dispatch($objetivo->id);

        return $objetivo->load('rol');
    }

    /**
     * ¿Es el objetivo el único superAdmin activo? Un superAdmin desactivado no cuenta.
     *
     * Debe llamarse dentro de una transacción: `lockForUpdate` bloquea las filas de los superAdmin
     * activos hasta el commit, así una segunda petición concurrente espera y ve el resultado de la
     * primera (PostgreSQL). Sin transacción el bloqueo no protegería nada.
     */
    private function esUltimoSuperAdminActivo(User $objetivo): bool
    {
        if (! $objetivo->esSuperAdmin()) {
            return false;
        }

        $activos = User::query()
            ->where('activo', true)
            ->whereHas('rol', fn ($q) => $q->where('es_super', true))
            ->lockForUpdate()
            ->pluck('id');

        return $activos->count() <= 1;
    }
}
