<?php

namespace App\Services\Asistente\Rag;

use App\Models\MntDocumentoFragmento;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de almacenamiento y recuperación vectorial (pgvector) con fallback en SQLite.
 */
class VectorStoreService
{
    /**
     * Guarda o actualiza un fragmento técnico con su vector de embedding.
     *
     * @param  array<int, float>  $embedding
     * @param  array<string, mixed>  $metadata
     */
    public function guardarFragmento(
        int $idDocumento,
        string $seccion,
        string $contenido,
        array $embedding,
        array $metadata = []
    ): MntDocumentoFragmento {
        $embeddingFormat = '['.implode(',', $embedding).']';

        return MntDocumentoFragmento::create([
            'id_documento_tecnico' => $idDocumento,
            'titulo_seccion' => $seccion,
            'contenido' => $contenido,
            'metadata' => $metadata,
            'embedding' => $embeddingFormat,
        ]);
    }

    /**
     * Busca los fragmentos zootécnicos más similares al vector consultado.
     *
     * @param  array<int, float>  $vectorQuery
     * @return array<int, array<string, mixed>>
     */
    public function buscarSimilares(array $vectorQuery, int $limite = 3, ?string $especie = null): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            return $this->buscarPostgres($vectorQuery, $limite, $especie);
        }

        return $this->buscarFallbackSqlite($vectorQuery, $limite, $especie);
    }

    /**
     * Búsqueda nativa con pgvector e índice HNSW en PostgreSQL.
     *
     * @param  array<int, float>  $vectorQuery
     * @return array<int, array<string, mixed>>
     */
    private function buscarPostgres(array $vectorQuery, int $limite, ?string $especie): array
    {
        $vectorStr = '['.implode(',', $vectorQuery).']';

        $query = DB::table('mnt_documentos_fragmentos as f')
            ->join('ctl_documentos_tecnicos as d', 'd.id', '=', 'f.id_documento_tecnico')
            ->where('d.activo', true)
            ->select([
                'f.id',
                'f.id_documento_tecnico',
                'f.titulo_seccion',
                'f.contenido',
                'f.metadata',
                'd.titulo as documento_titulo',
                'd.especie as documento_especie',
                'd.autor as documento_autor',
            ])
            ->selectRaw('(f.embedding <=> ?::vector) as distancia', [$vectorStr]);

        if ($especie !== null && trim($especie) !== '') {
            $query->where(function ($q) use ($especie) {
                $q->where('d.especie', $especie)
                    ->orWhereNull('d.especie');
            });
        }

        return $query->orderBy('distancia', 'asc')
            ->limit($limite)
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'id_fragmento' => $row->id,
                    'id_documento_tecnico' => $row->id_documento_tecnico,
                    'documento_titulo' => $row->documento_titulo,
                    'documento_especie' => $row->documento_especie,
                    'documento_autor' => $row->documento_autor,
                    'titulo_seccion' => $row->titulo_seccion,
                    'contenido' => $row->contenido,
                    'metadata' => is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata,
                    'similitud' => round(1 - (float) $row->distancia, 4),
                ];
            })
            ->toArray();
    }

    /**
     * Búsqueda por cálculo de distancia coseno en PHP para SQLite y entornos de pruebas.
     *
     * @param  array<int, float>  $vectorQuery
     * @return array<int, array<string, mixed>>
     */
    private function buscarFallbackSqlite(array $vectorQuery, int $limite, ?string $especie): array
    {
        $query = DB::table('mnt_documentos_fragmentos as f')
            ->join('ctl_documentos_tecnicos as d', 'd.id', '=', 'f.id_documento_tecnico')
            ->where('d.activo', true)
            ->select([
                'f.id',
                'f.id_documento_tecnico',
                'f.titulo_seccion',
                'f.contenido',
                'f.metadata',
                'f.embedding',
                'd.titulo as documento_titulo',
                'd.especie as documento_especie',
                'd.autor as documento_autor',
            ]);

        if ($especie !== null && trim($especie) !== '') {
            $query->where(function ($q) use ($especie) {
                $q->where('d.especie', $especie)
                    ->orWhereNull('d.especie');
            });
        }

        $fragmentos = $query->get();
        $calculados = [];

        foreach ($fragmentos as $frag) {
            $raw = $frag->embedding;
            if (is_string($raw)) {
                $raw = trim($raw, '[]');
                $vectorRow = array_map('floatval', explode(',', $raw));
            } else {
                $vectorRow = (array) $raw;
            }

            $distancia = $this->calcularDistanciaCoseno($vectorQuery, $vectorRow);

            $calculados[] = [
                'id' => $frag->id,
                'id_fragmento' => $frag->id,
                'id_documento_tecnico' => $frag->id_documento_tecnico,
                'documento_titulo' => $frag->documento_titulo,
                'documento_especie' => $frag->documento_especie,
                'documento_autor' => $frag->documento_autor,
                'titulo_seccion' => $frag->titulo_seccion,
                'contenido' => $frag->contenido,
                'metadata' => is_string($frag->metadata) ? json_decode($frag->metadata, true) : (array) $frag->metadata,
                'distancia' => $distancia,
                'similitud' => round(1 - $distancia, 4),
            ];
        }

        usort($calculados, fn ($a, $b) => $a['distancia'] <=> $b['distancia']);

        return array_slice($calculados, 0, $limite);
    }

    /**
     * Calcula la distancia coseno entre dos vectores (0 = idénticos, 2 = opuestos).
     *
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function calcularDistanciaCoseno(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        $count = count($a);
        for ($i = 0; $i < $count; $i++) {
            $valA = (float) ($a[$i] ?? 0.0);
            $valB = (float) ($b[$i] ?? 0.0);

            $dotProduct += $valA * $valB;
            $normA += $valA * $valA;
            $normB += $valB * $valB;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 1.0;
        }

        $similitud = $dotProduct / (sqrt($normA) * sqrt($normB));

        return 1.0 - $similitud;
    }
}
