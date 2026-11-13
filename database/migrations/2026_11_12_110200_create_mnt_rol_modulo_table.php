<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mnt_rol_modulo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_rol')->constrained('mnt_roles')->restrictOnDelete();
            $table->foreignId('id_modulo')->constrained('mnt_modulos')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['id_rol', 'id_modulo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnt_rol_modulo');
    }
};
