<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mnt_modulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_modulo_padre')
                ->nullable()
                ->constrained('mnt_modulos')
                ->restrictOnDelete();
            $table->string('clave')->unique();
            $table->string('nombre');
            $table->string('ruta')->nullable();
            $table->string('icono')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('exacto')->default(false);
            $table->boolean('activo')->default(true);
            $table->boolean('solo_super')->default(false);
            $table->boolean('base')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnt_modulos');
    }
};
