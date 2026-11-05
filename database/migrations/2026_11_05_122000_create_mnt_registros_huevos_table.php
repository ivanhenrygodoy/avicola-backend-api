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
        Schema::create('mnt_registros_huevos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_division')
                ->constrained('mnt_divisiones')
                ->restrictOnDelete();
            $table->foreignId('id_usuario')
                ->nullable()
                ->constrained('mnt_usuarios')
                ->nullOnDelete();
            $table->date('fecha');
            $table->unsignedInteger('cantidad_recolectada');
            $table->unsignedInteger('cantidad_rotos')->default(0);
            $table->unsignedInteger('cantidad_aprovechables')->default(0);
            $table->text('observaciones')->nullable();
            $table->timestamps();

            $table->index(['fecha', 'id_division']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mnt_registros_huevos');
    }
};

