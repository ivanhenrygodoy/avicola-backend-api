<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensajes de cada conversación del asistente. `rol` distingue al usuario del modelo para
     * poder pintarlos en el chat; `en_contexto` marca los que pueden reenviarse al modelo.
     */
    public function up(): void
    {
        Schema::create('mnt_mensajes_asistente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_conversacion_asistente')
                ->constrained('mnt_conversaciones_asistente')
                ->restrictOnDelete();
            $table->string('rol', 10);
            $table->text('contenido');
            $table->json('herramientas')->nullable();
            $table->json('sugerencias_modulos')->nullable();
            $table->boolean('es_error')->default(false);
            $table->boolean('en_contexto')->default(true);
            $table->timestamp('created_at')->nullable();

            $table->index(['id_conversacion_asistente', 'id'], 'idx_mensajes_asistente_conversacion');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnt_mensajes_asistente');
    }
};
