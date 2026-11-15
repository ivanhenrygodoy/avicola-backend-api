<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mnt_configuracion_tema', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_componente_tema')
                ->constrained('ctl_componentes_tema')
                ->restrictOnDelete();
            $table->string('modo', 20)->default('claro'); // claro | nocturno
            $table->string('aspecto', 60);
            $table->string('etiqueta', 120);
            $table->string('variable_css', 80);
            $table->string('tipo_control', 20)->default('color'); // color | shadow
            $table->string('valor_actual', 120);
            $table->string('valor_defecto', 120);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['id_componente_tema', 'modo', 'aspecto'], 'mnt_config_tema_comp_modo_aspecto_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mnt_configuracion_tema');
    }
};

