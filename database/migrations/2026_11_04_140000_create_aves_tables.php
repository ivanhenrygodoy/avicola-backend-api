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
        Schema::create('mnt_aves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_division')
                ->nullable()
                ->constrained('mnt_divisiones')
                ->nullOnDelete();
            $table->string('codigo')->unique();
            $table->string('nombre')->nullable();
            $table->string('especie'); // Gallina, Chompipe, Pato
            $table->string('sexo')->default('Desconocido'); // Macho, Hembra, Desconocido
            $table->string('raza')->nullable(); // Criolla, Pichiche, etc.
            $table->string('proposito')->nullable(); // Postura, Reproducción, Ornamental, Crianza, Carne
            $table->date('fecha_nacimiento')->nullable();
            $table->date('fecha_ingreso')->nullable();
            $table->string('estado')->default('activa'); // activa, trasladada, fallecida, salida
            $table->decimal('peso_actual_kg', 8, 2)->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });

        Schema::create('mnt_movimientos_aves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_ave')
                ->constrained('mnt_aves')
                ->cascadeOnDelete();
            $table->foreignId('id_division_origen')
                ->nullable()
                ->constrained('mnt_divisiones')
                ->nullOnDelete();
            $table->foreignId('id_division_destino')
                ->nullable()
                ->constrained('mnt_divisiones')
                ->nullOnDelete();
            $table->dateTime('fecha');
            $table->string('motivo')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mnt_movimientos_aves');
        Schema::dropIfExists('mnt_aves');
    }
};
