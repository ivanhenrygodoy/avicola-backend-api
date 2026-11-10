<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Herramientas (tools) ejecutadas en cada turno del asistente y tablas que consultaron.
     */
    public function up(): void
    {
        Schema::create('mnt_funcion_log_agent_tools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_funcion_log_agent')
                ->constrained('mnt_funcion_log_agent')
                ->restrictOnDelete();
            $table->unsignedSmallInteger('orden')->default(1);
            $table->string('herramienta', 100)->index();
            $table->json('argumentos')->nullable();
            $table->json('tablas_consultadas')->nullable();
            $table->text('resumen_resultado')->nullable();
            $table->boolean('exito')->default(true);
            $table->unsignedInteger('duracion_ms')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnt_funcion_log_agent_tools');
    }
};
