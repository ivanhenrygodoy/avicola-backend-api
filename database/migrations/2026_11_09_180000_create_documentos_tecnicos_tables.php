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
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
        }

        Schema::create('ctl_documentos_tecnicos', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 150);
            $table->string('especie', 50)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('autor', 100)->nullable();
            $table->string('version', 20)->default('1.0');
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index(['activo', 'especie'], 'idx_documentos_activo_especie');
        });

        Schema::create('mnt_documentos_fragmentos', function (Blueprint $table) use ($driver) {
            $table->id();
            $table->foreignId('id_documento_tecnico')
                ->constrained('ctl_documentos_tecnicos', 'id')
                ->cascadeOnDelete();
            $table->string('titulo_seccion', 150);
            $table->text('contenido');
            $table->json('metadata')->nullable();

            if ($driver === 'pgsql') {
                $table->addColumn('vector', 'embedding', ['dimensions' => 768])->nullable();
            } else {
                $table->text('embedding')->nullable();
            }

            $table->timestamps();

            $table->index('id_documento_tecnico', 'idx_fragmentos_documento');
        });

        if ($driver === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_fragmentos_embedding_hnsw ON mnt_documentos_fragmentos USING hnsw (embedding vector_cosine_ops);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mnt_documentos_fragmentos');
        Schema::dropIfExists('ctl_documentos_tecnicos');
    }
};
