<?php

namespace Database\Factories;

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolesYModulosSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // Ruling 2: por defecto superAdmin, para que los tests existentes no reciban 403.
            'id_rol' => fn () => $this->idRol(Rol::CLAVE_SUPER_ADMIN),
            'activo' => true,
            'debe_cambiar_password' => false,
        ];
    }

    /**
     * Id del rol sembrado por la migración de datos (lo crea si la BD de prueba no lo tiene).
     */
    protected function idRol(string $clave): int
    {
        if (! Rol::where('clave', $clave)->exists()) {
            (new RolesYModulosSeeder)->run();
        }

        return Rol::where('clave', $clave)->value('id');
    }

    public function operario(): static
    {
        return $this->state(fn () => ['id_rol' => $this->idRol(Rol::CLAVE_OPERARIO)]);
    }

    public function sinRol(): static
    {
        return $this->state(fn () => ['id_rol' => null]);
    }

    public function inactivo(): static
    {
        return $this->state(fn () => ['activo' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
