<?php

namespace Tests\Feature;

use App\Models\CtlDocumentoTecnico;
use App\Services\Asistente\GeminiApiClient;
use App\Services\Asistente\Rag\IngestaManualesService;
use App\Services\Asistente\Rag\RrfFusionService;
use App\Services\Asistente\Rag\VectorStoreService;
use App\Services\Asistente\Tools\ConsultarManualesTecnicosRAGTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RagTest extends TestCase
{
    use RefreshDatabase;

    public function test_modelos_documento_tecnico_y_fragmentos_persisten_correctamente(): void
    {
        $documento = CtlDocumentoTecnico::create([
            'titulo' => 'Manual Técnico del Pato Pichiche',
            'especie' => 'Pato',
            'descripcion' => 'Guía completa sobre manejo, sanidad y alimentación.',
            'autor' => 'Zootecnia Avícola',
            'version' => '1.0',
            'activo' => true,
        ]);

        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'id' => $documento->id,
            'titulo' => 'Manual Técnico del Pato Pichiche',
            'especie' => 'Pato',
        ]);

        $fragmento = $documento->fragmentos()->create([
            'titulo_seccion' => 'Alimentación en Etapa de Crecimiento',
            'contenido' => 'El pato pichiche requiere una dieta con 18% de proteína cruda y acceso a agua limpia.',
            'metadata' => ['categoria' => 'nutricion', 'pagina' => 12],
            'embedding' => json_encode(array_fill(0, 768, 0.05)),
        ]);

        $this->assertDatabaseHas('mnt_documentos_fragmentos', [
            'id' => $fragmento->id,
            'titulo_seccion' => 'Alimentación en Etapa de Crecimiento',
        ]);

        $this->assertInstanceOf(CtlDocumentoTecnico::class, $fragmento->documentoTecnico);
        $this->assertEquals($documento->id, $fragmento->documentoTecnico->id);
        $this->assertCount(1, $documento->fragmentos);
    }

    public function test_gemini_api_client_genera_embedding_correctamente(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'embedding' => [
                    'values' => array_fill(0, 768, 0.0123),
                ],
            ], 200),
        ]);

        $client = new GeminiApiClient('dummy_key');
        $embedding = $client->generarEmbedding('Alimentación de patos pichiches');

        $this->assertIsArray($embedding);
        $this->assertCount(768, $embedding);
        $this->assertEquals(0.0123, $embedding[0]);
    }

    public function test_vector_store_service_guarda_y_busca_fragmentos_similares(): void
    {
        $documento = CtlDocumentoTecnico::create([
            'titulo' => 'Guía del Pato Pichiche',
            'especie' => 'Pato',
            'activo' => true,
        ]);

        $vectorNutricion = array_fill(0, 768, 0.1);
        $vectorSanidad = array_fill(0, 768, -0.1);

        $vectorStore = app(VectorStoreService::class);

        $vectorStore->guardarFragmento(
            $documento->id,
            'Nutrición y Raciones',
            'Ración diaria balanceada para patos.',
            $vectorNutricion,
            ['tema' => 'alimentacion']
        );

        $vectorStore->guardarFragmento(
            $documento->id,
            'Vacunación y Sanidad',
            'Plan de vacunación contra Newcastle.',
            $vectorSanidad,
            ['tema' => 'sanidad']
        );

        // Búsqueda con vector cercano a Nutrición
        $vectorQuery = array_fill(0, 768, 0.09);
        $resultados = $vectorStore->buscarSimilares($vectorQuery, 1, 'Pato');

        $this->assertCount(1, $resultados);
        $this->assertEquals('Nutrición y Raciones', $resultados[0]['titulo_seccion']);
        $this->assertStringContainsString('Ración diaria', $resultados[0]['contenido']);
    }

    public function test_ingesta_manuales_service_crea_catalogo_y_fragmentos(): void
    {
        $ingestaService = app(IngestaManualesService::class);
        $resumen = $ingestaService->ingestarManuales(simularEmbeddings: true);

        $this->assertEquals(3, $resumen['documentos_creados']);
        $this->assertGreaterThanOrEqual(9, $resumen['fragmentos_creados']);

        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'especie' => 'Pato',
        ]);
        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'especie' => 'Chompipe',
        ]);
        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'especie' => 'Gallina',
        ]);
    }

    public function test_consultar_manuales_tecnicos_rag_tool_ejecuta_correctamente(): void
    {
        $ingestaService = app(IngestaManualesService::class);
        $ingestaService->ingestarManuales(simularEmbeddings: true);

        // Simulamos respuesta de embedding para la tool
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'embedding' => [
                    'values' => array_fill(0, 768, 0.05),
                ],
            ], 200),
        ]);

        $tool = app(ConsultarManualesTecnicosRAGTool::class);
        $resultado = $tool->ejecutar([
            'consulta' => '¿Qué cuidados y alimentación requiere el pato pichiche?',
            'especie' => 'Pato',
        ], ['id_usuario' => 1]);

        $this->assertArrayHasKey('total_fragmentos', $resultado);
        $this->assertGreaterThan(0, $resultado['total_fragmentos']);
        $this->assertNotEmpty($resultado['fragmentos']);
        $this->assertStringContainsString('Pato', $resultado['fragmentos'][0]['documento']);
    }

    public function test_consultar_manuales_tecnicos_rag_tool_retorna_orientacion_pedagogica_cuando_no_hay_coincidencias(): void
    {
        $tool = app(ConsultarManualesTecnicosRAGTool::class);
        $resultado = $tool->ejecutar([
            'consulta' => '¿Cómo criar avestruces?',
            'especie' => 'Avestruz',
        ], ['id_usuario' => 1]);

        $this->assertSame(0, $resultado['total_fragmentos']);
        $this->assertFalse($resultado['encontrado']);
        $this->assertArrayHasKey('orientacion_pedagogica', $resultado);
        $this->assertStringContainsString('veterinario', $resultado['orientacion_pedagogica']);
    }

    public function test_rrf_fusion_service_combina_y_prioriza_fragmentos_en_ambos_rankings(): void
    {
        $rrf = app(RrfFusionService::class);

        $vectoriales = [
            ['id_fragmento' => 10, 'titulo_seccion' => 'Solo Vectorial 1', 'similitud' => 0.95],
            ['id_fragmento' => 20, 'titulo_seccion' => 'En Ambas', 'similitud' => 0.85],
        ];

        $lexicos = [
            ['id_fragmento' => 30, 'titulo_seccion' => 'Solo Léxico 1', 'score_lexico' => 5.0],
            ['id_fragmento' => 20, 'titulo_seccion' => 'En Ambas', 'score_lexico' => 4.0],
        ];

        $resultado = $rrf->fusionar($vectoriales, $lexicos, limite: 3, k: 60);

        $this->assertCount(3, $resultado);
        $this->assertSame(20, $resultado[0]['id_fragmento']);
        $this->assertSame('ambas', $resultado[0]['origen_recuperacion']);
        $this->assertSame(2, $resultado[0]['rank_vectorial']);
        $this->assertSame(2, $resultado[0]['rank_lexico']);
    }

    public function test_busqueda_hibrida_rescata_termino_exacto_y_respeta_filtros(): void
    {
        $idPublicada = DB::table('ctl_estado_fuente_conocimiento')->where('clave', 'publicada')->value('id');
        $idBorrador = DB::table('ctl_estado_fuente_conocimiento')->where('clave', 'borrador')->value('id');

        $docPublicado = CtlDocumentoTecnico::create([
            'titulo' => 'Manual de Bioseguridad Gallina Criolla',
            'especie' => 'Gallina',
            'id_estado' => $idPublicada,
            'activo' => true,
        ]);

        $docBorrador = CtlDocumentoTecnico::create([
            'titulo' => 'Manual en Borrador',
            'especie' => 'Gallina',
            'id_estado' => $idBorrador,
            'activo' => true,
        ]);

        $vectorStore = app(VectorStoreService::class);

        // Fragmento 1: vector idéntico al query pero sin el código exacto
        $vectorCercano = array_fill(0, 768, 0.5);
        $vectorStore->guardarFragmento(
            $docPublicado->id,
            'Alojamiento General',
            'Los corrales deben mantenerse ventilados y con cama seca.',
            $vectorCercano
        );

        // Fragmento 2: vector ortogonal/lejano pero contiene el código exacto NDV-LaSota
        $vectorLejano = array_fill(0, 768, -0.5);
        $vectorStore->guardarFragmento(
            $docPublicado->id,
            'Protocolo Vacunal NDV-LaSota',
            'Aplicar cepa NDV-LaSota vía ocular al día 7 y refuerzo al día 21.',
            $vectorLejano
        );

        // Fragmento 3: en borrador, no debe aparecer aunque coincida en texto y vector
        $vectorStore->guardarFragmento(
            $docBorrador->id,
            'Borrador NDV-LaSota',
            'Contenido en borrador con NDV-LaSota.',
            $vectorCercano
        );

        $resultados = $vectorStore->buscarHibrido('dosis cepa NDV-LaSota', $vectorCercano, limite: 3, especie: 'Gallina');

        $this->assertCount(2, $resultados);
        $titulos = array_column($resultados, 'titulo_seccion');
        $this->assertContains('Protocolo Vacunal NDV-LaSota', $titulos);
        $this->assertContains('Alojamiento General', $titulos);
        $this->assertNotContains('Borrador NDV-LaSota', $titulos);
    }

    public function test_consultar_manuales_tecnicos_rag_tool_reporta_estrategia_hibrida_y_resiliente_sin_embedding(): void
    {
        $idPublicada = DB::table('ctl_estado_fuente_conocimiento')->where('clave', 'publicada')->value('id');

        $doc = CtlDocumentoTecnico::create([
            'titulo' => 'Manual Técnico de Gallinas',
            'especie' => 'Gallina',
            'id_estado' => $idPublicada,
            'activo' => true,
        ]);

        $vectorStore = app(VectorStoreService::class);
        $vectorStore->guardarFragmento(
            $doc->id,
            'Prevención Viruela Aviar',
            'Vacunar contra viruela aviar en el pliegue del ala a las 6 semanas.',
            array_fill(0, 768, 0.2)
        );

        // Caso 1: Embedding disponible -> estrategia híbrida; Caso 2: Falla API -> resiliente por rama léxica
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['embedding' => ['values' => array_fill(0, 768, 0.2)]], 200)
                ->push(['error' => 'Service Unavailable'], 503),
        ]);

        $tool = app(ConsultarManualesTecnicosRAGTool::class);
        $resHibrido = $tool->ejecutar([
            'consulta' => 'vacuna viruela aviar',
            'especie' => 'Gallina',
        ], ['id_usuario' => 1]);

        $this->assertTrue($resHibrido['encontrado']);
        $this->assertSame('hibrida', $resHibrido['estrategia_busqueda']);
        $this->assertStringContainsString('búsqueda híbrida', $resHibrido['mensaje']);

        $resLexico = $tool->ejecutar([
            'consulta' => 'viruela aviar',
            'especie' => 'Gallina',
        ], ['id_usuario' => 1]);

        $this->assertTrue($resLexico['encontrado']);
        $this->assertSame('lexica', $resLexico['estrategia_busqueda']);
        $this->assertStringContainsString('búsqueda léxica', $resLexico['mensaje']);
    }
}
