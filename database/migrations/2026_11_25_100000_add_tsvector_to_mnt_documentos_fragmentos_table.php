<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Agrega columna tsvector generada e índice GIN para búsqueda híbrida en PostgreSQL.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("
            ALTER TABLE mnt_documentos_fragmentos
            ADD COLUMN IF NOT EXISTS tsv tsvector GENERATED ALWAYS AS (
                setweight(to_tsvector('spanish', coalesce(titulo_seccion, '')), 'A') ||
                setweight(to_tsvector('spanish', coalesce(contenido, '')), 'B')
            ) STORED
        ");

        DB::statement('CREATE INDEX IF NOT EXISTS idx_fragmentos_tsv_gin ON mnt_documentos_fragmentos USING gin (tsv)');
    }

    /**
     * Revierte la columna tsvector y su índice GIN en PostgreSQL.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_fragmentos_tsv_gin');
        DB::statement('ALTER TABLE mnt_documentos_fragmentos DROP COLUMN IF EXISTS tsv');
    }
};
