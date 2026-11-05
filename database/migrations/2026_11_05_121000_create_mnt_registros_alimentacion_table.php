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
        Schema::create('mnt_registros_alimentacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_division')
                ->constrained('mnt_divisiones')
                ->restrictOnDelete();
            $table->foreignId('id_tipo_alimento')
                ->constrained('ctl_tipos_alimento')
                ->restrictOnDelete();
            $table->foreignId('id_usuario')
                ->nullable()
                ->constrained('mnt_usuarios')
                ->nullOnDelete();
            $table->date('fecha');
            $table->time('hora')->nullable();
            $table->decimal('cantidad', 8, 2);
            $table->string('unidad_medida', 20)->default('kg');
            $table->decimal('costo_total', 10, 2)->nullable();
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
        Schema::dropIfExists('mnt_registros_alimentacion');
    }
};

