<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conversaciones del asistente AviaCore, varias por usuario. Nunca se borran: "eliminar"
     * solo cambia el estado para que dejen de aparecerle al usuario.
     */
    public function up(): void
    {
        Schema::create('mnt_conversaciones_asistente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_usuario')
                ->constrained('mnt_usuarios')
                ->restrictOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('nombre', 80)->nullable();
            $table->string('nombre_origen', 12)->default('pendiente');
            $table->string('estado', 12)->default('activa');
            $table->timestamp('eliminada_at')->nullable();
            $table->timestamp('ultima_actividad_at')->nullable();
            $table->timestamps();

            $table->index(['id_usuario', 'estado', 'ultima_actividad_at'], 'idx_conv_asistente_usuario_estado_actividad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnt_conversaciones_asistente');
    }
};
