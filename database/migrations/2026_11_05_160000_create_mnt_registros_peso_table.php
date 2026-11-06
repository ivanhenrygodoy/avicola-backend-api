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
        Schema::create('mnt_registros_peso', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_ave')
                ->constrained('mnt_aves')
                ->cascadeOnDelete();
            $table->foreignId('id_usuario')
                ->nullable()
                ->constrained('mnt_usuarios')
                ->nullOnDelete();
            $table->date('fecha');
            $table->decimal('peso_kg', 8, 2);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mnt_registros_peso');
    }
};

