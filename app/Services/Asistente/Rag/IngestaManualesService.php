<?php

namespace App\Services\Asistente\Rag;

use App\Models\CtlDocumentoTecnico;
use App\Services\Asistente\GeminiApiClient;

class IngestaManualesService
{
    public function __construct(
        private GeminiApiClient $geminiClient,
        private VectorStoreService $vectorStore
    ) {}

    /**
     * Retorna la literatura técnica estructurada de la granja avícola.
     *
     * @return array<int, array<string, mixed>>
     */
    public function obtenerManualesBase(): array
    {
        return [
            [
                'titulo' => 'Manual Técnico de Crianza y Sanidad del Pato Pichiche (Dendrocygna autumnalis)',
                'especie' => 'Pato',
                'autor' => 'Departamento Técnico Zootécnico Avícola',
                'version' => '1.2',
                'descripcion' => 'Guía integral sobre requerimientos nutricionales, sanidad, instalaciones y bioseguridad para patos pichiches.',
                'secciones' => [
                    [
                        'titulo' => 'Alimentación y Nutrición del Pato Pichiche',
                        'contenido' => 'El pato pichiche requiere una dieta con 18-20% de proteína cruda en fase de iniciación y 15-16% en mantenimiento. Es vital suministrar bebederos con profundidad suficiente para que limpien sus orificios nasales y humedezcan los alimentos secos, evitando atragantamientos. Se complementa con forrajes verdes picados y granos enteros como maíz y sorgo.',
                        'metadata' => ['tema' => 'alimentacion', 'etapa' => 'general'],
                    ],
                    [
                        'titulo' => 'Sanidad, Prevención y Enfermedades Comunes en Patos',
                        'contenido' => 'Los patos son altamente sensibles a micotoxinas presentes en granos húmedos (aflatoxicosis) y a la enteritis vírica del pato (peste del pato). Se debe realizar desparasitación interna cada 3 meses y mantener las camas de viruta secas y aireadas para prevenir brotes respiratorios causados por el hongo Aspergillus fumigatus.',
                        'metadata' => ['tema' => 'sanidad', 'enfermedades' => ['aflatoxicosis', 'peste_pato', 'aspergilosis']],
                    ],
                    [
                        'titulo' => 'Instalaciones, Confort y Regla Biológica de Aislamiento',
                        'contenido' => 'Se requiere un área mínima de 1.2 m² por pato adulto bajo techo y acceso a piletas de agua limpia. Rige una estricta regla biológica: los patos nunca deben convivir en la misma división con gallinas criollas debido a la transmisión cruzada de parásitos digestivos y bacterias que comprometen la salud de ambas especies.',
                        'metadata' => ['tema' => 'instalaciones', 'regla' => 'separacion_especies'],
                    ],
                ],
            ],
            [
                'titulo' => 'Guía Zootécnica para la Producción y Manejo de Pavos y Chompipes',
                'especie' => 'Chompipe',
                'autor' => 'Departamento Técnico Zootécnico Avícola',
                'version' => '1.0',
                'descripcion' => 'Manual de manejo de pavos y chompipes criollos: nutrición de alta proteína, prevención de perosis y bioseguridad.',
                'secciones' => [
                    [
                        'titulo' => 'Nutrición y Exigencias Proteicas del Chompipe',
                        'contenido' => 'Los chompipes presentan las demandas de proteína más elevadas entre las aves de corral: 26% a 28% de proteína cruda durante las primeras 4 semanas de vida, descendiendo gradualmente a 20% en desarrollo y 16% en finalización. Es imprescindible asegurar niveles óptimos de calcio, fósforo y manganeso para prevenir perosis (deformación de las patas).',
                        'metadata' => ['tema' => 'nutricion', 'requerimiento' => 'alta_proteina'],
                    ],
                    [
                        'titulo' => 'Bioseguridad y Prevención de Histomoniasis (Cabeza Negra)',
                        'contenido' => 'La Histomoniasis o enfermedad de la cabeza negra, causada por el protozoo Histomonas meleagridis, es letal para los pavos. Este protozoo se hospeda en el parásito cecal Heterakis gallinarum, portado comúnmente por gallinas sanas. Por esta razón biológica es mandatorio no alojar pavos en divisiones adyacentes o mezclados con gallinas.',
                        'metadata' => ['tema' => 'bioseguridad', 'enfermedad' => 'histomoniasis'],
                    ],
                    [
                        'titulo' => 'Manejo de Espacio y Reducción del Estrés en Chompipes',
                        'contenido' => 'El chompipe es propenso al pánico y amontonamientos por ruidos o cambios abruptos de temperatura. Requiere al menos 2 m² por ave adulta en corral y perchas redondeadas a baja altura (máximo 40 cm del piso) para evitar fracturas o contusiones en la quilla de la pechuga.',
                        'metadata' => ['tema' => 'manejo_etologia', 'espacio_minimo' => '2m2'],
                    ],
                ],
            ],
            [
                'titulo' => 'Manual de Manejo Integral y Bioseguridad de la Gallina Criolla',
                'especie' => 'Gallina',
                'autor' => 'Departamento Técnico Zootécnico Avícola',
                'version' => '2.0',
                'descripcion' => 'Directrices de manejo de postura, nidos, plan vacunal preventivo y confort en casitas.',
                'secciones' => [
                    [
                        'titulo' => 'Manejo de Nidos y Recolección de Huevos',
                        'contenido' => 'Para maximizar la recolección y evitar roturas, se debe proveer 1 nidal oscuro y limpio con viruta por cada 4 a 5 gallinas ponedoras. La recolección debe efectuarse al menos 2 veces al día (mañana y tarde). Los huevos aprovechables se calculan deduciendo las posturas rotas o fisuradas de la recolección total bruta.',
                        'metadata' => ['tema' => 'postura', 'nidos' => '1_por_5_aves'],
                    ],
                    [
                        'titulo' => 'Plan Sanitario y Vacunación en Gallinas Criollas',
                        'contenido' => 'El plan preventivo básico incluye inmunización obligatoria contra la enfermedad de Newcastle (cepa La Sota), viruela aviar por punción alar y bronquitis infecciosa. Para control de ectoparásitos (ácaro rojo Dermanyssus gallinae y piojos masticadores), se recomiendan cajones de baño de arena volcánica con ceniza vegetal y tierra de diatomeas.',
                        'metadata' => ['tema' => 'sanidad', 'vacunas' => ['newcastle', 'viruela', 'bronquitis']],
                    ],
                    [
                        'titulo' => 'Bioseguridad de Instalaciones y Rotación de Corrales',
                        'contenido' => 'El vacío sanitario entre lotes debe ser de al menos 14 días con flameado o encalado de pisos y paredes. Los bebederos deben lavarse y desinfectarse a diario. Se debe respetar el principio de todo dentro / todo fuera y mantener la división física estricta respecto a patos y chompipes.',
                        'metadata' => ['tema' => 'bioseguridad', 'vacio_sanitario' => '14_dias'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Ingesta los manuales y sus fragmentos en la base vectorial.
     *
     * @return array{documentos_creados: int, fragmentos_creados: int}
     */
    public function ingestarManuales(bool $simularEmbeddings = false): array
    {
        $manuales = $this->obtenerManualesBase();
        $totalDocumentos = 0;
        $totalFragmentos = 0;

        foreach ($manuales as $manualData) {
            $secciones = $manualData['secciones'];
            unset($manualData['secciones']);

            $documento = CtlDocumentoTecnico::updateOrCreate(
                [
                    'titulo' => $manualData['titulo'],
                    'especie' => $manualData['especie'],
                ],
                $manualData
            );
            $totalDocumentos++;

            foreach ($secciones as $sec) {
                $textoParaEmbedding = $sec['titulo']."\n\n".$sec['contenido'];

                if ($simularEmbeddings) {
                    // Genera vector pseudo-determinista de 768 floats basado en el hash del texto
                    $hash = md5($textoParaEmbedding);
                    $vector = [];
                    for ($i = 0; $i < 768; $i++) {
                        $charIndex = $i % 32;
                        $vector[] = round((hexdec($hash[$charIndex]) - 8) / 10.0, 4);
                    }
                } else {
                    $vector = $this->geminiClient->generarEmbedding($textoParaEmbedding);
                }

                $this->vectorStore->guardarFragmento(
                    $documento->id,
                    $sec['titulo'],
                    $sec['contenido'],
                    $vector,
                    $sec['metadata'] ?? []
                );
                $totalFragmentos++;
            }
        }

        return [
            'documentos_creados' => $totalDocumentos,
            'fragmentos_creados' => $totalFragmentos,
        ];
    }
}
