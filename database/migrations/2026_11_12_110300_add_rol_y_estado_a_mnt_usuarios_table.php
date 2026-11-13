<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mnt_usuarios', function (Blueprint $table) {
            $table->foreignId('id_rol')
                ->nullable()
                ->after('password')
                ->constrained('mnt_roles')
                ->restrictOnDelete();
            $table->boolean('activo')->default(true)->after('id_rol');
            $table->boolean('debe_cambiar_password')->default(false)->after('activo');
            // Estado del envío de credenciales por cola (R21): pendiente | enviado | fallido.
            $table->string('credenciales_estado', 20)->nullable()->after('debe_cambiar_password');
            $table->string('credenciales_error')->nullable()->after('credenciales_estado');
        });
    }

    public function down(): void
    {
        Schema::table('mnt_usuarios', function (Blueprint $table) {
            $table->dropConstrainedForeignId('id_rol');
            $table->dropColumn(['activo', 'debe_cambiar_password', 'credenciales_estado', 'credenciales_error']);
        });
    }
};
