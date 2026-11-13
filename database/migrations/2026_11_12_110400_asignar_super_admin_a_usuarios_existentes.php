<?php

use App\Models\Rol;
use App\Models\User;
use Database\Seeders\RolesYModulosSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Migración de datos (Ruling 4): siembra roles y módulos (idempotente) y asigna `super_admin`
 * a los usuarios que aún no tienen rol, para que el sistema no quede sin acceso tras desplegar.
 * Después de esto, un `id_rol` nulo significa «sin permisos» (nunca superAdmin).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RolesYModulosSeeder)->run();

        $idSuper = Rol::where('clave', Rol::CLAVE_SUPER_ADMIN)->value('id');

        User::query()->whereNull('id_rol')->update(['id_rol' => $idSuper]);
    }

    public function down(): void
    {
        // Los datos de negocio no se borran al revertir.
    }
};
