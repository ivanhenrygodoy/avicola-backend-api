<?php

namespace App\Services\Asistente\Rag;

use App\Models\MntDocumentoFragmento;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de almacenamiento y recuperación RAG (vectorial pgvector + léxica tsvector) con fallback en SQLite.
 */
class VectorStoreService
{
    private const STOPWORDS_ES = [
        'de', 'la', 'el', 'en', 'los', 'las', 'un', 'una', 'unos', 'unas',
        'del', 'al', 'por', 'para', 'con', 'sin', 'sobre', 'entre', 'que',
        'como', 'cual', 'cuales', 'se', 'su', 'sus', 'es', 'son', 'o', 'y',
    ];

    public function __construct(
        private ?RrfFusionService $rrfFusion = null
    ) {}

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
        if (DB::getDriverName() === 'pgsql') {
            return $this->buscarPostgres($vectorQuery, $limite, $especie);
        }

        return $this->buscarFallbackSqlite($vectorQuery, $limite, $especie);
    }

    /**
     * Búsqueda híbrida: combina similitud vectorial y búsqueda léxica mediante Reciprocal Rank Fusion (RRF).
     *
     * @param  array<int, float>|null  $vectorQuery
     * @return array<int, array<string, mixed>>
     */
    public function buscarHibrido(
        string $consultaTexto,
        ?array $vectorQuery,
        int $limite = 3,
        ?string $especie = null
    ): array {
        $k = max(1, (int) config('asistente.rag.rrf_k', 60));
        $candidatos = max($limite * 5, (int) config('asistente.rag.candidatos_por_rama', 15));
        $pesoVectorial = (float) config('asistente.rag.peso_vectorial', 1.0);
        $pesoLexico = (float) config('asistente.rag.peso_lexico', 1.0);

        $tieneVector = $vectorQuery !== null && $vectorQuery !== [];
        $tieneTexto = trim($consultaTexto) !== '';

        if (DB::getDriverName() === 'pgsql') {
            $vectoriales = $tieneVector ? $this->buscarPostgres($vectorQuery, $candidatos, $especie) : [];
            $lexicos = $tieneTexto ? $this->buscarLexicoPostgres($consultaTexto, $candidatos, $especie) : [];
        } else {
            $vectoriales = $tieneVector ? $this->buscarFallbackSqlite($vectorQuery, $candidatos, $especie) : [];
            $lexicos = $tieneTexto ? $this->buscarLexicoSqlite($consultaTexto, $candidatos, $especie) : [];
        }

        $rrf = $this->rrfFusion ??= new RrfFusionService;

        return $rrf->fusionar(
            $vectoriales,
            $lexicos,
            $limite,
            $k,
            $pesoVectorial,
            $pesoLexico
        );
    }

    /**
     * Query base compartida con filtros obligatorios de publicación y especie opcional.
     */
    private function baseFragmentosQuery(?string $especie): Builder
    {
        $query = DB::table('mnt_documentos_fragmentos as f')
            ->join('ctl_documentos_tecnicos as d', 'd.id', '=', 'f.id_documento_tecnico')
            ->join('ctl_estado_fuente_conocimiento as est', 'est.id', '=', 'd.id_estado')
            ->where('d.activo', true)
            ->where('est.clave', 'publicada')
            ->select([
                'f.id',
                'f.id_documento_tecnico',
                'f.titulo_seccion',
                'f.contenido',
                'f.metadata',
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

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapearFilaFragmento(object $row, ?float $similitud = null, ?float $scoreLexico = null): array
    {
        $item = [
            'id' => $row->id,
            'id_fragmento' => $row->id,
            'id_documento_tecnico' => $row->id_documento_tecnico,
            'documento_titulo' => $row->documento_titulo,
            'documento_especie' => $row->documento_especie,
            'documento_autor' => $row->documento_autor,
            'titulo_seccion' => $row->titulo_seccion,
            'contenido' => $row->contenido,
            'metadata' => is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata,
        ];

        if ($similitud !== null) {
            $item['similitud'] = $similitud;
        }

        if ($scoreLexico !== null) {
            $item['score_lexico'] = $scoreLexico;
        }

        return $item;
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

        return $this->baseFragmentosQuery($especie)
            ->selectRaw('(f.embedding <=> ?::vector) as distancia', [$vectorStr])
            ->orderBy('distancia', 'asc')
            ->limit($limite)
            ->get()
            ->map(fn ($row) => $this->mapearFilaFragmento($row, similitud: round(1 - (float) $row->distancia, 4)))
            ->toArray();
    }

    /**
     * Búsqueda léxica nativa con tsvector e índice GIN en PostgreSQL.
     *
     * Usa plainto_tsquery convertido a OR (|) para tolerar sinónimos o palabras adicionales
     * reformuladas por el LLM, ordenando por relevancia acumulada (ts_rank).
     *
     * @return array<int, array<string, mixed>>
     */
    private function buscarLexicoPostgres(string $consultaTexto, int $limite, ?string $especie): array
    {
        $tsQuerySql = "replace(plainto_tsquery('spanish', ?)::text, '&', '|')::tsquery";

        return $this->baseFragmentosQuery($especie)
            ->selectRaw("ts_rank(f.tsv, {$tsQuerySql}) as score_lexico", [$consultaTexto])
            ->whereRaw("f.tsv @@ {$tsQuerySql}", [$consultaTexto])
            ->orderBy('score_lexico', 'desc')
            ->limit($limite)
            ->get()
            ->map(fn ($row) => $this->mapearFilaFragmento($row, scoreLexico: round((float) $row->score_lexico, 6)))
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
        $fragmentos = $this->baseFragmentosQuery($especie)
            ->addSelect('f.embedding')
            ->get();

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
            $item = $this->mapearFilaFragmento($frag, similitud: round(1 - $distancia, 4));
            $item['distancia'] = $distancia;

            $calculados[] = $item;
        }

        usort($calculados, fn ($a, $b) => $a['distancia'] <=> $b['distancia']);

        return array_slice($calculados, 0, $limite);
    }

    /**
     * Búsqueda léxica en PHP para SQLite y entornos de pruebas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buscarLexicoSqlite(string $consultaTexto, int $limite, ?string $especie): array
    {
        $tokens = $this->tokenizarTexto($consultaTexto);
        if ($tokens === []) {
            return [];
        }

        $fragmentos = $this->baseFragmentosQuery($especie)->get();
        $coincidentes = [];

        foreach ($fragmentos as $frag) {
            $tituloNorm = $this->normalizarTexto((string) $frag->titulo_seccion);
            $contenidoNorm = $this->normalizarTexto((string) $frag->contenido);

            $score = 0.0;
            foreach ($tokens as $token) {
                if (str_contains($tituloNorm, $token)) {
                    $score += 2.0;
                }

                $ocurrencias = substr_count($contenidoNorm, $token);
                if ($ocurrencias > 0) {
                    $score += min(3.0, (float) $ocurrencias);
                }
            }

            if ($score > 0.0) {
                $coincidentes[] = $this->mapearFilaFragmento($frag, scoreLexico: $score);
            }
        }

        usort($coincidentes, fn ($a, $b) => $b['score_lexico'] <=> $a['score_lexico']);

        return array_slice($coincidentes, 0, $limite);
    }

    /**
     * @return array<int, string>
     */
    private function tokenizarTexto(string $texto): array
    {
        $normalizado = $this->normalizarTexto($texto);
        $partes = preg_split('/[^a-z0-9]+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_filter($partes, function (string $token): bool {
            return strlen($token) >= 2 && ! in_array($token, self::STOPWORDS_ES, true);
        });

        return array_values(array_unique($tokens));
    }

    private function normalizarTexto(string $texto): string
    {
        $minusculas = mb_strtolower($texto, 'UTF-8');

        return strtr($minusculas, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);
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
