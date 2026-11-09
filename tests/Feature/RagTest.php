<?php

namespace Tests\Feature;

use App\Models\CtlDocumentoTecnico;
use App\Services\Asistente\GeminiApiClient;
use App\Services\Asistente\Rag\IngestaManualesService;
use App\Services\Asistente\Rag\VectorStoreService;
use App\Services\Asistente\Tools\ConsultarManualesTecnicosRAGTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'id_documento_tecnico' => $documento->id_documento_tecnico,
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
            'id_fragmento' => $fragmento->id_fragmento,
            'titulo_seccion' => 'Alimentación en Etapa de Crecimiento',
        ]);

        $this->assertInstanceOf(CtlDocumentoTecnico::class, $fragmento->documentoTecnico);
        $this->assertEquals($documento->id_documento_tecnico, $fragmento->documentoTecnico->id_documento_tecnico);
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
            $documento->id_documento_tecnico,
            'Nutrición y Raciones',
            'Ración diaria balanceada para patos.',
            $vectorNutricion,
            ['tema' => 'alimentacion']
        );

        $vectorStore->guardarFragmento(
            $documento->id_documento_tecnico,
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
}
