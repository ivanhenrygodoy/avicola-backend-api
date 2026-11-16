<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ctl_documentos_tecnicos', function (Blueprint $table) {
            $table->foreignId('id_estado')
                ->nullable()
                ->after('id')
                ->constrained('ctl_estado_fuente_conocimiento', 'id')
                ->restrictOnDelete();
            $table->string('tipo_fuente', 20)->default('documento')->after('especie');
            $table->string('nombre_archivo_original', 255)->nullable()->after('version');
            $table->string('ruta_archivo', 255)->nullable()->after('nombre_archivo_original');
            $table->unsignedBigInteger('tamano_bytes')->nullable()->after('ruta_archivo');
            $table->string('tipo_mime', 100)->nullable()->after('tamano_bytes');
            $table->unsignedInteger('total_palabras')->default(0)->after('tipo_mime');
            $table->timestamp('fecha_procesado')->nullable()->after('total_palabras');

            $table->index(['id_estado', 'especie'], 'idx_documentos_estado_especie');
        });

        // Asignar estado 'publicada' a los manuales existentes
        $idPublicada = DB::table('ctl_estado_fuente_conocimiento')->where('clave', 'publicada')->value('id');
        if ($idPublicada) {
            DB::table('ctl_documentos_tecnicos')
                ->whereNull('id_estado')
                ->update([
                    'id_estado' => $idPublicada,
                    'fecha_procesado' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ctl_documentos_tecnicos', function (Blueprint $table) {
            $table->dropIndex('idx_documentos_estado_especie');
            $table->dropForeign(['id_estado']);
            $table->dropColumn([
                'id_estado',
                'tipo_fuente',
                'nombre_archivo_original',
                'ruta_archivo',
                'tamano_bytes',
                'tipo_mime',
                'total_palabras',
                'fecha_procesado',
            ]);
        });
    }
};

