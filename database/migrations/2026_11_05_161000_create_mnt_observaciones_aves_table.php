<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mnt_observaciones_aves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_ave')
                ->constrained('mnt_aves')
                ->cascadeOnDelete();
            $table->foreignId('id_usuario')
                ->nullable()
                ->constrained('mnt_usuarios')
                ->nullOnDelete();
            $table->date('fecha');
            $table->string('tipo'); // Salud, Comportamiento, Vacunación, Incidencia, Nutrición
            $table->string('titulo');
            $table->text('descripcion');
            $table->text('accion_tomada')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mnt_observaciones_aves');
    }
};

