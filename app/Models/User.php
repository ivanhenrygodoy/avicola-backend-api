<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name', 'email', 'password', 'id_rol', 'activo', 'debe_cambiar_password',
    'credenciales_estado', 'credenciales_error',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Nombre de la tabla con convención mnt_
     */
    protected $table = 'mnt_usuarios';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'activo' => 'boolean',
            'debe_cambiar_password' => 'boolean',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'id_rol');
    }

    public function esSuperAdmin(): bool
    {
        return $this->activo && $this->rol?->es_super === true;
    }

    /**
     * Claves de los módulos a los que puede acceder (R4, R22, Ruling 6).
     * superAdmin → todos los activos; sin rol o desactivado → ninguno.
     *
     * @return array<int, string>
     */
    public function clavesPermitidas(): array
    {
        if (! $this->activo || $this->id_rol === null) {
            return [];
        }

        $rol = $this->rol;

        if ($rol === null) {
            return [];
        }

        if ($rol->es_super) {
            return Modulo::where('activo', true)->pluck('clave')->all();
        }

        return $rol->modulos()->where('mnt_modulos.activo', true)->pluck('clave')->all();
    }

    public function puedeAcceder(string $clave): bool
    {
        return in_array($clave, $this->clavesPermitidas(), true);
    }
}
