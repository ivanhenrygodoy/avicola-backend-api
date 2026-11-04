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
        Schema::create('mnt_granjas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('mnt_casitas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_granja')
                ->constrained('mnt_granjas')
                ->restrictOnDelete();
            $table->foreignId('id_tipo_instalacion')
                ->nullable()
                ->constrained('ctl_tipo_instalacion')
                ->nullOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('tipo')->nullable(); // Compatibilidad
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('mnt_divisiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_casita')
                ->constrained('mnt_casitas')
                ->restrictOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mnt_divisiones');
        Schema::dropIfExists('mnt_casitas');
        Schema::dropIfExists('mnt_granjas');
    }
};

