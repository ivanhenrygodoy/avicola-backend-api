<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ctl_estado_fuente_conocimiento', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 50)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 255)->nullable();
            $table->string('color_badge', 50)->default('neutral');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Sembrado inicial de estados garantizados
        $ahora = now();
        DB::table('ctl_estado_fuente_conocimiento')->insert([
            [
                'clave' => 'borrador',
                'nombre' => 'Borrador',
                'descripcion' => 'Documento registrado pero pendiente de fragmentación y vectorización.',
                'color_badge' => 'neutral',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave' => 'publicada',
                'nombre' => 'Publicada',
                'descripcion' => 'Fuente vectorizada y activa para las consultas zootécnicas del asistente.',
                'color_badge' => 'success',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'clave' => 'archivada',
                'nombre' => 'Archivada',
                'descripcion' => 'Fuente retirada del asistente RAG conservando su trazabilidad histórica.',
                'color_badge' => 'warning',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ctl_estado_fuente_conocimiento');
    }
};

