<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de auditoría del asistente AviaCore: un registro por turno.
     * Por confidencialidad NO guarda ninguna referencia al usuario que escribió el mensaje.
     */
    public function up(): void
    {
        Schema::create('mnt_funcion_log_agent', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid_conversacion')->nullable()->index();
            $table->text('mensaje_usuario');
            $table->unsignedSmallInteger('turnos_historial')->default(0);
            $table->string('prompt_version', 20)->nullable();
            $table->text('respuesta_agente')->nullable();
            $table->text('respuesta_original_llm')->nullable();
            $table->string('resultado', 20)->index();
            $table->json('guards_aplicados')->nullable();
            $table->string('guards_nombres')->nullable();
            $table->text('error_mensaje')->nullable();
            $table->unsignedInteger('tokens_prompt')->default(0);
            $table->unsignedInteger('tokens_respuesta')->default(0);
            $table->unsignedInteger('tokens_total')->default(0);
            $table->unsignedSmallInteger('iteraciones')->default(0);
            $table->unsignedInteger('duracion_ms')->default(0);
            $table->string('modelo', 80)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnt_funcion_log_agent');
    }
};
