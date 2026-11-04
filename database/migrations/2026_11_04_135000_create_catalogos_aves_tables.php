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
        Schema::create('ctl_especie', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique(); // Gallina, Chompipe, Pato
            $table->string('descripcion')->nullable();
            $table->string('icono', 10)->nullable(); // 🐔, 🦃, 🦆
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('ctl_sexo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique(); // Hembra, Macho, Desconocido
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ctl_sexo');
        Schema::dropIfExists('ctl_especie');
    }
};
