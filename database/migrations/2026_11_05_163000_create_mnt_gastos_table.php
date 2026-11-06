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
        Schema::create('mnt_gastos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_categoria_gasto')
                ->constrained('ctl_categorias_gastos')
                ->restrictOnDelete();
            $table->foreignId('id_division')
                ->nullable()
                ->constrained('mnt_divisiones')
                ->nullOnDelete();
            $table->foreignId('id_usuario')
                ->nullable()
                ->constrained('mnt_usuarios')
                ->nullOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 10, 2);
            $table->string('concepto');
            $table->string('proveedor')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mnt_gastos');
    }
};

