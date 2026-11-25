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

    public function tablasConsultadas(): array
    {
        return ['mnt_documentos_fragmentos', 'ctl_documentos_tecnicos'];
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
            // Si falla la API de embeddings, se omite el vector para que la rama léxica responda sin ruido
            $vector = null;
        }

        $fragmentos = $this->vectorStore->buscarHibrido($consulta, $vector, limite: 3, especie: $especie);

        if (empty($fragmentos)) {
            return [
                'total_fragmentos' => 0,
                'encontrado' => false,
                'estrategia_busqueda' => 'sin_resultados',
                'mensaje' => 'No se encontraron fragmentos técnicos en los manuales oficiales de la granja para la consulta especificada.',
                'orientacion_pedagogica' => 'Explica con calidez y pedagogía que los manuales técnicos oficiales de la granja (enfocados en Gallina Criolla, Chompipe y Pato Pichiche) no disponen de lineamientos específicos o comprobados para esta consulta o especie. Recomiéndale al operario consultar directamente con el médico veterinario o encargado zootécnico antes de tomar decisiones de manejo o convivencia, o invítale a formular su consulta sobre las temáticas y especies cubiertas.',
            ];
        }

        $estrategia = $this->resolverEstrategia($fragmentos, $vector !== null);
        $total = count($fragmentos);

        return [
            'total_fragmentos' => $total,
            'encontrado' => true,
            'estrategia_busqueda' => $estrategia,
            'mensaje' => $this->formatearMensajeEstrategia($total, $estrategia),
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

    /**
     * Determina qué motores aportaron los fragmentos finalmente seleccionados.
     *
     * @param  array<int, array<string, mixed>>  $fragmentos
     */
    private function resolverEstrategia(array $fragmentos, bool $conVector): string
    {
        if (! $conVector) {
            return 'lexica';
        }

        $origenes = array_unique(array_map(
            fn (array $f): string => (string) ($f['origen_recuperacion'] ?? 'vectorial'),
            $fragmentos
        ));

        if (in_array('ambas', $origenes, true) || count($origenes) > 1) {
            return 'hibrida';
        }

        return $origenes[0] === 'lexica' ? 'lexica' : 'vectorial';
    }

    private function formatearMensajeEstrategia(int $total, string $estrategia): string
    {
        $etiqueta = match ($estrategia) {
            'hibrida' => 'búsqueda híbrida (vectorial + léxica)',
            'lexica' => 'búsqueda léxica',
            default => 'búsqueda vectorial',
        };

        return "Se recuperaron {$total} fragmentos mediante {$etiqueta}.";
    }
}
