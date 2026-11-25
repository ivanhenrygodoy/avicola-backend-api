<?php

namespace App\Services\Asistente\Rag;

/**
 * Combina rankings de búsqueda vectorial y léxica mediante Reciprocal Rank Fusion (RRF).
 */
class RrfFusionService
{
    /**
     * Fusiona dos listas ordenadas de fragmentos y retorna los mejores según su puntaje RRF.
     *
     * @param  array<int, array<string, mixed>>  $resultadosVectoriales
     * @param  array<int, array<string, mixed>>  $resultadosLexicos
     * @return array<int, array<string, mixed>>
     */
    public function fusionar(
        array $resultadosVectoriales,
        array $resultadosLexicos,
        int $limite = 3,
        int $k = 60,
        float $pesoVectorial = 1.0,
        float $pesoLexico = 1.0
    ): array {
        $kSeguro = max(1, $k);
        $acumulados = [];

        $this->acumularRama($acumulados, $resultadosVectoriales, 'vectorial', $pesoVectorial, $kSeguro);
        $this->acumularRama($acumulados, $resultadosLexicos, 'lexica', $pesoLexico, $kSeguro);

        $fusionados = array_values($acumulados);

        usort($fusionados, function (array $a, array $b): int {
            $cmpScore = $b['score_rrf'] <=> $a['score_rrf'];
            if ($cmpScore !== 0) {
                return $cmpScore;
            }

            return ($b['similitud'] ?? 0.0) <=> ($a['similitud'] ?? 0.0);
        });

        return array_slice($fusionados, 0, max(1, $limite));
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $acumulados
     * @param  array<int, array<string, mixed>>  $resultados
     */
    private function acumularRama(
        array &$acumulados,
        array $resultados,
        string $rama,
        float $peso,
        int $k
    ): void {
        if ($peso <= 0.0) {
            return;
        }

        foreach (array_values($resultados) as $indice => $item) {
            $id = $item['id_fragmento'] ?? $item['id'];
            $rank = $indice + 1;
            $contribucion = $peso / ($k + $rank);

            if (! isset($acumulados[$id])) {
                $acumulados[$id] = array_merge($item, [
                    'score_rrf' => 0.0,
                    'rank_vectorial' => null,
                    'rank_lexico' => null,
                    'origen_recuperacion' => $rama,
                ]);
            } elseif ($acumulados[$id]['origen_recuperacion'] !== $rama) {
                $acumulados[$id]['origen_recuperacion'] = 'ambas';
            }

            $acumulados[$id]['score_rrf'] = round($acumulados[$id]['score_rrf'] + $contribucion, 6);

            if ($rama === 'vectorial') {
                $acumulados[$id]['rank_vectorial'] = $rank;
                $acumulados[$id]['similitud'] = $item['similitud'] ?? $acumulados[$id]['similitud'] ?? null;
            } else {
                $acumulados[$id]['rank_lexico'] = $rank;
            }
        }
    }
}
