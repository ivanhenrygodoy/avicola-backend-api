<?php

namespace App\Services\Asistente\Tools;

use App\Services\Asistente\Contracts\AsistenteToolInterface;
use App\Services\Asistente\GeminiApiClient;
use App\Services\Asistente\Rag\VectorStoreService;
use Throwable;

/**
 * Herramienta RAG para consultar manuales técnicos zootécnicos mediante búsqueda semántica vectorial.
 */
class ConsultarManualesTecnicosRAGTool implements AsistenteToolInterface
{
    public function __construct(
        private GeminiApiClient $geminiClient,
        private VectorStoreService $vectorStore
    ) {}

    public function nombre(): string
    {
        return 'consultar_manuales_tecnicos';
    }

    public function descripcion(): string
    {
        return 'Consulta la base de conocimiento técnico y manuales zootécnicos sobre crianza, nutrición, sanidad, prevención de enfermedades y bioseguridad de aves (gallinas criollas, chompipes/pavos y patos pichiches). Debe utilizarse siempre que el usuario haga preguntas sobre cuidados, requerimientos de alimentación, enfermedades o recomendaciones biológicas.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'consulta' => [
                    'type' => 'STRING',
                    'description' => 'Texto descriptivo de la pregunta o duda técnica del usuario (ejemplo: "alimentación patos pichiches", "prevención viruela aviar", "espacio por chompipe").',
                ],
                'especie' => [
                    'type' => 'STRING',
                    'description' => 'Filtro opcional por especie de ave (Gallina, Chompipe, Pato).',
                ],
            ],
            'required' => ['consulta'],
        ];
    }

    public function ejecutar(array $argumentos, array $contextoFijo = []): array
    {
        $consulta = trim((string) ($argumentos['consulta'] ?? ''));
        $especie = isset($argumentos['especie']) && trim($argumentos['especie']) !== ''
            ? trim($argumentos['especie'])
            : null;

        if ($consulta === '') {
            return [
                'total_fragmentos' => 0,
                'mensaje' => 'No se especificó ninguna consulta técnica válida.',
            ];
        }

        try {
            $vector = $this->geminiClient->generarEmbedding($consulta);
        } catch (Throwable) {
            // Fallback determinista en entornos sin clave o testing
            $hash = md5($consulta);
            $vector = [];
            for ($i = 0; $i < 768; $i++) {
                $charIndex = $i % 32;
                $vector[] = round((hexdec($hash[$charIndex]) - 8) / 10.0, 4);
            }
        }

        $fragmentos = $this->vectorStore->buscarSimilares($vector, limite: 3, especie: $especie);

        if (empty($fragmentos)) {
            return [
                'total_fragmentos' => 0,
                'encontrado' => false,
                'mensaje' => 'No se encontraron fragmentos técnicos en los manuales oficiales de la granja para la consulta especificada.',
                'orientacion_pedagogica' => 'Explica con calidez y pedagogía que los manuales técnicos oficiales de la granja (enfocados en Gallina Criolla, Chompipe y Pato Pichiche) no disponen de lineamientos específicos o comprobados para esta consulta o especie. Recomiéndale al operario consultar directamente con el médico veterinario o encargado zootécnico antes de tomar decisiones de manejo o convivencia, o invítale a formular su consulta sobre las temáticas y especies cubiertas.',
            ];
        }

        return [
            'total_fragmentos' => count($fragmentos),
            'encontrado' => true,
            'fragmentos' => array_map(function ($f) {
                return [
                    'documento' => $f['documento_titulo'],
                    'seccion' => $f['titulo_seccion'],
                    'especie' => $f['documento_especie'],
                    'contenido' => $f['contenido'],
                ];
            }, $fragmentos),
        ];
    }
}
