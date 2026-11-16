<?php

namespace Tests\Feature;

use App\Models\CtlDocumentoTecnico;
use App\Models\CtlEstadoFuenteConocimiento;
use App\Models\Rol;
use App\Models\User;
use App\Services\Asistente\Rag\VectorStoreService;
use Database\Seeders\EstadoFuenteConocimientoSeeder;
use Database\Seeders\RolesYModulosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FuenteConocimientoTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $operario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesYModulosSeeder::class);
        $this->seed(EstadoFuenteConocimientoSeeder::class);

        $rolSuper = Rol::where('clave', Rol::CLAVE_SUPER_ADMIN)->firstOrFail();
        $rolOperario = Rol::where('clave', Rol::CLAVE_OPERARIO)->firstOrFail();

        $this->superAdmin = User::factory()->create([
            'id_rol' => $rolSuper->id,
            'email' => 'admin@granja.local',
        ]);

        $this->operario = User::factory()->create([
            'id_rol' => $rolOperario->id,
            'email' => 'operario@granja.local',
        ]);

        Storage::fake('local');
    }

    public function test_operario_no_puede_acceder_a_fuentes_de_conocimiento(): void
    {
        $response = $this->actingAs($this->operario)->getJson('/api/v1/asistente/fuentes');
        $response->assertStatus(403);
    }

    public function test_super_admin_puede_listar_fuentes_y_kpis(): void
    {
        $idPublicada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::PUBLICADA)->value('id');

        CtlDocumentoTecnico::create([
            'id_estado' => $idPublicada,
            'titulo' => 'Guía Zootécnica de Aves',
            'especie' => 'Gallina',
            'activo' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)->getJson('/api/v1/asistente/fuentes');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'total'],
                'stats' => ['total_fuentes', 'total_publicadas', 'total_fragmentos', 'especies_cubiertas'],
            ]);
    }

    public function test_super_admin_puede_registrar_fuente_en_borrador_con_texto_directo(): void
    {
        $payload = [
            'tipo_fuente' => 'texto',
            'titulo' => 'Protocolo de Vacunación Newcastle',
            'especie' => 'Gallina',
            'descripcion' => 'Instrucciones para la aplicación de vacuna ocular La Sota.',
            'autor' => 'Dr. Zootecnista',
            'version' => '1.0',
            'texto_contenido' => "El plan de vacunación contra Newcastle debe aplicarse a los 7 días de edad.\n\nSe recomienda repetir dosis a las 4 semanas para asegurar títulos de anticuerpos protectores.",
            'accion' => 'borrador',
        ];

        $response = $this->actingAs($this->superAdmin)->postJson('/api/v1/asistente/fuentes', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.titulo', 'Protocolo de Vacunación Newcastle')
            ->assertJsonPath('data.estado.clave', 'borrador')
            ->assertJsonPath('data.fragmentos_count', 0);

        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'titulo' => 'Protocolo de Vacunación Newcastle',
            'tipo_fuente' => 'texto',
        ]);
    }

    public function test_super_admin_puede_procesar_borrador_existente(): void
    {
        $idBorrador = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::BORRADOR)->value('id');

        $rutaPrueba = 'documentos_rag/manual_test.txt';
        Storage::disk('local')->put($rutaPrueba, "# Nutrición de Patos\n\nLos patos requieren 18% de proteína cruda en fase de inicio.\n\n# Sanidad\n\nDesparasitación cada 3 meses.");

        $documento = CtlDocumentoTecnico::create([
            'id_estado' => $idBorrador,
            'titulo' => 'Manual de Patos',
            'especie' => 'Pato',
            'tipo_fuente' => 'documento',
            'autor' => 'Dr. Zootecnia',
            'version' => '1.0',
            'descripcion' => 'Manual de manejo y nutrición de patos.',
            'ruta_archivo' => $rutaPrueba,
            'nombre_archivo_original' => 'manual_test.txt',
            'activo' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)->postJson("/api/v1/asistente/fuentes/{$documento->id}/procesar");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado.clave', 'publicada');

        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'id' => $documento->id,
            'id_estado' => CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::PUBLICADA)->value('id'),
        ]);

        // Verificar que se crearon los fragmentos troceados
        $this->assertDatabaseHas('mnt_documentos_fragmentos', [
            'id_documento_tecnico' => $documento->id,
        ]);
    }

    public function test_super_admin_puede_alternar_entre_publicada_y_archivada(): void
    {
        $idPublicada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::PUBLICADA)->value('id');
        $idArchivada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::ARCHIVADA)->value('id');

        $documento = CtlDocumentoTecnico::create([
            'id_estado' => $idPublicada,
            'titulo' => 'Manual Antiguo de Pavos',
            'especie' => 'Chompipe',
            'activo' => true,
        ]);

        // Primer toggle: pasa a archivada
        $res1 = $this->actingAs($this->superAdmin)->patchJson("/api/v1/asistente/fuentes/{$documento->id}/alternar-estado");
        $res1->assertOk()
            ->assertJsonPath('data.estado.clave', 'archivada');

        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'id' => $documento->id,
            'id_estado' => $idArchivada,
        ]);

        // Segundo toggle: regresa a publicada
        $res2 = $this->actingAs($this->superAdmin)->patchJson("/api/v1/asistente/fuentes/{$documento->id}/alternar-estado");
        $res2->assertOk()
            ->assertJsonPath('data.estado.clave', 'publicada');

        $this->assertDatabaseHas('ctl_documentos_tecnicos', [
            'id' => $documento->id,
            'id_estado' => $idPublicada,
        ]);
    }

    public function test_fuente_archivada_no_es_consultada_por_vector_store(): void
    {
        $idPublicada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::PUBLICADA)->value('id');
        $idArchivada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::ARCHIVADA)->value('id');

        // Documento publicado
        $docPublicado = CtlDocumentoTecnico::create([
            'id_estado' => $idPublicada,
            'titulo' => 'Documento Activo',
            'especie' => 'Gallina',
            'activo' => true,
        ]);

        // Documento archivado
        $docArchivado = CtlDocumentoTecnico::create([
            'id_estado' => $idArchivada,
            'titulo' => 'Documento Inactivo',
            'especie' => 'Gallina',
            'activo' => true,
        ]);

        $vectorDummy = array_fill(0, 768, 0.1);

        $vectorStore = app(VectorStoreService::class);
        $vectorStore->guardarFragmento($docPublicado->id, 'Sec 1', 'Texto activo', $vectorDummy);
        $vectorStore->guardarFragmento($docArchivado->id, 'Sec 2', 'Texto archivado', $vectorDummy);

        $resultados = $vectorStore->buscarSimilares($vectorDummy, 10, 'Gallina');

        // Debe retornar únicamente el fragmento del documento publicado
        $this->assertCount(1, $resultados);
        $this->assertEquals($docPublicado->id, $resultados[0]['id_documento_tecnico']);
    }

    public function test_super_admin_puede_descargar_archivo_fuente(): void
    {
        $idPublicada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::PUBLICADA)->value('id');

        $rutaArchivo = 'documentos_rag/prueba_descarga.txt';
        Storage::disk('local')->put($rutaArchivo, 'Contenido zootécnico original.');

        $documento = CtlDocumentoTecnico::create([
            'id_estado' => $idPublicada,
            'titulo' => 'Manual Descargable',
            'nombre_archivo_original' => 'prueba_descarga.txt',
            'ruta_archivo' => $rutaArchivo,
            'tipo_mime' => 'text/plain',
            'activo' => true,
        ]);

        $response = $this->actingAs($this->superAdmin)->get("/api/v1/asistente/fuentes/{$documento->id}/descargar");
        $response->assertOk();
    }

    public function test_validacion_rechaza_campos_vacios_al_procesar(): void
    {
        $response = $this->actingAs($this->superAdmin)->postJson('/api/v1/asistente/fuentes', [
            'tipo_fuente' => 'texto',
            'titulo' => '',
            'especie' => '',
            'autor' => '',
            'version' => '',
            'descripcion' => '',
            'texto_contenido' => '',
            'accion' => 'procesar',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['titulo', 'especie', 'autor', 'version', 'descripcion', 'texto_contenido']);
    }

    public function test_guardar_borrador_solo_requiere_documento_o_texto(): void
    {
        $response = $this->actingAs($this->superAdmin)->postJson('/api/v1/asistente/fuentes', [
            'tipo_fuente' => 'texto',
            'titulo' => '',
            'especie' => '',
            'autor' => '',
            'version' => '',
            'descripcion' => '',
            'texto_contenido' => 'Texto técnico preliminar guardado sin completar metadatos todavía.',
            'accion' => 'borrador',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.estado.clave', 'borrador');
    }

    public function test_super_admin_puede_editar_borrador_y_rechaza_edicion_si_ya_esta_publicada(): void
    {
        $idBorrador = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::BORRADOR)->value('id');
        $idPublicada = CtlEstadoFuenteConocimiento::where('clave', CtlEstadoFuenteConocimiento::PUBLICADA)->value('id');

        $borrador = CtlDocumentoTecnico::create([
            'id_estado' => $idBorrador,
            'titulo' => 'Borrador Inicial',
            'especie' => 'Gallina',
            'tipo_fuente' => 'texto',
            'autor' => 'Autor Original',
            'version' => '1.0',
            'descripcion' => 'Desc original',
            'activo' => true,
        ]);

        $resEdit = $this->actingAs($this->superAdmin)->putJson("/api/v1/asistente/fuentes/{$borrador->id}", [
            'tipo_fuente' => 'texto',
            'titulo' => 'Borrador Actualizado',
            'especie' => 'Chompipe',
            'autor' => 'Dr. Actualizado',
            'version' => '2.0',
            'descripcion' => 'Descripción completa actualizada.',
            'texto_contenido' => 'Contenido zootécnico actualizado para el borrador de pavos.',
            'accion' => 'borrador',
        ]);

        $resEdit->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.titulo', 'Borrador Actualizado')
            ->assertJsonPath('data.especie', 'Chompipe')
            ->assertJsonPath('data.version', '2.0');

        $publicada = CtlDocumentoTecnico::create([
            'id_estado' => $idPublicada,
            'titulo' => 'Fuente Publicada',
            'especie' => 'Gallina',
            'tipo_fuente' => 'texto',
            'autor' => 'Dr. Test',
            'version' => '1.0',
            'descripcion' => 'Desc',
            'activo' => true,
        ]);

        $resReject = $this->actingAs($this->superAdmin)->putJson("/api/v1/asistente/fuentes/{$publicada->id}", [
            'tipo_fuente' => 'texto',
            'titulo' => 'Intento Editar',
            'especie' => 'Gallina',
            'autor' => 'Dr. Test',
            'version' => '1.0',
            'descripcion' => 'Desc',
            'texto_contenido' => 'Contenido de prueba para validar rechazo de edición.',
        ]);

        $resReject->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}

