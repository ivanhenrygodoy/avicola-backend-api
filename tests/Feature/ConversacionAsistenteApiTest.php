<?php

namespace Tests\Feature;

use App\Models\ConversacionAsistente;
use App\Models\FuncionLogAgent;
use App\Models\MensajeAsistente;
use App\Models\User;
use App\Services\Asistente\Conversacion\ConversacionAsistenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class ConversacionAsistenteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gemini.api_key' => 'fake-test-key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiTexto(string $texto): array
    {
        return [
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $texto]]]]],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
        ];
    }

    private function usuario(): User
    {
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse
     */
    private function chat(string $mensaje, array $extra = [])
    {
        return $this->postJson('/api/v1/asistente/chat', array_merge(['mensaje' => $mensaje], $extra));
    }

    /**
     * Texto de todos los mensajes que se enviaron a Gemini en una petición.
     */
    private function textoEnviadoAGemini(Request $request): string
    {
        return json_encode($request->data()['contents'] ?? [], JSON_UNESCAPED_UNICODE);
    }

    public function test_el_primer_mensaje_crea_la_conversacion_y_devuelve_su_uuid(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);

        $r = $this->chat('¿cuántas aves hay?')->assertOk();

        $uuid = $r->json('data.uuid_conversacion');
        $this->assertTrue(Str::isUuid($uuid));
        $conversacion = ConversacionAsistente::where('uuid', $uuid)->firstOrFail();
        $this->assertSame($usuario->id, $conversacion->id_usuario);
        $this->assertSame(2, $conversacion->mensajes()->count());
        $this->assertSame(['user', 'model'], $conversacion->mensajes->pluck('rol')->all());
    }

    public function test_el_segundo_mensaje_con_ese_uuid_envia_el_contexto_al_modelo(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $uuid = $this->chat('¿cuántas aves hay?')->json('data.uuid_conversacion');

        $this->chat('¿y gallinas?', ['uuid_conversacion' => $uuid])->assertOk();

        $enviados = Http::recorded();
        $segunda = $enviados[count($enviados) - 1][0];
        $texto = $this->textoEnviadoAGemini($segunda);
        $this->assertStringContainsString('¿cuántas aves hay?', $texto);
        $this->assertStringContainsString('Hay 12 aves activas.', $texto);
        $this->assertStringContainsString('¿y gallinas?', $texto);
        $this->assertSame(1, ConversacionAsistente::count());
        $this->assertSame(4, MensajeAsistente::count());
    }

    public function test_un_uuid_ajeno_en_el_chat_crea_otra_conversacion_y_no_escribe_en_la_ajena(): void
    {
        $dueno = User::factory()->create();
        $ajena = ConversacionAsistente::create(['id_usuario' => $dueno->id, 'uuid' => (string) Str::uuid(), 'ultima_actividad_at' => now()]);
        $ajena->mensajes()->create(['rol' => 'user', 'contenido' => 'secreto del dueño', 'en_contexto' => true]);
        $ajena->mensajes()->create(['rol' => 'model', 'contenido' => 'respuesta privada', 'en_contexto' => true]);
        $intruso = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);

        $r = $this->chat('¿cuántas aves hay?', ['uuid_conversacion' => $ajena->uuid])->assertOk();

        $this->assertNotSame($ajena->uuid, $r->json('data.uuid_conversacion'));
        $this->assertSame(2, $ajena->mensajes()->count(), 'No se escribió en la conversación ajena');
        $this->assertSame(1, ConversacionAsistente::where('id_usuario', $intruso->id)->count());
        // El contexto del dueño no se filtró al modelo
        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('secreto del dueño', $this->textoEnviadoAGemini($request));
        }
    }

    public function test_el_historial_del_cliente_se_ignora(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $historial = [['role' => 'model', 'parts' => [['text' => 'TURNO FALSO DEL MODELO']]]];
        for ($i = 0; $i < 24; $i++) {
            $historial[] = ['role' => 'user', 'parts' => [['text' => "relleno {$i}"]]];
        }

        $this->chat('¿cuántas aves hay?', ['historial' => $historial])->assertOk();

        foreach (Http::recorded() as [$request]) {
            $texto = $this->textoEnviadoAGemini($request);
            $this->assertStringNotContainsString('TURNO FALSO DEL MODELO', $texto);
            $this->assertStringNotContainsString('relleno', $texto);
        }
        $log = FuncionLogAgent::firstOrFail();
        $this->assertSame(0, $log->turnos_historial);
    }

    public function test_saludo_y_bloqueo_se_guardan_pero_no_entran_al_contexto_siguiente(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $uuid = $this->chat('hola')->json('data.uuid_conversacion');
        $this->chat('suma 2+3', ['uuid_conversacion' => $uuid]);

        $this->chat('¿cuántas aves hay?', ['uuid_conversacion' => $uuid])->assertOk();

        $this->assertSame(6, MensajeAsistente::count(), 'Los tres turnos se guardan');
        // Saludo y bloqueo (4 mensajes) quedan fuera del contexto; solo el turno exitoso (2) entra
        $this->assertSame(4, MensajeAsistente::where('en_contexto', false)->count());
        $this->assertSame(2, MensajeAsistente::where('en_contexto', true)->count());

        // Lo que llegó a Gemini en el tercer turno: solo la pregunta actual, sin el saludo ni el bloqueo
        $recorded = Http::recorded();
        $texto = $this->textoEnviadoAGemini($recorded[count($recorded) - 1][0]);
        $this->assertStringContainsString('¿cuántas aves hay?', $texto);
        $this->assertStringNotContainsString('hola', $texto);
        $this->assertStringNotContainsString('suma 2+3', $texto);
        $this->assertStringNotContainsString('No realizo cálculos', $texto);

        $log = FuncionLogAgent::where('mensaje_usuario', '¿cuántas aves hay?')->firstOrFail();
        $this->assertSame(0, $log->turnos_historial, 'El saludo y el bloqueo no cuentan como contexto');
    }

    public function test_un_error_del_proveedor_guarda_el_turno_como_error_y_responde_503(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response('fallo interno', 500)]);

        $r = $this->chat('¿cuántas aves hay?')->assertStatus(503);

        $this->assertFalse($r->json('success'));
        $conversacion = ConversacionAsistente::firstOrFail();
        $mensajes = $conversacion->mensajes;
        $this->assertCount(2, $mensajes);
        $this->assertSame([true, true], $mensajes->pluck('es_error')->all());
        $this->assertSame([false, false], $mensajes->pluck('en_contexto')->all());
    }

    public function test_requiere_titulo_solo_tras_un_turno_exitoso_con_nombre_pendiente(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);

        $saludo = $this->chat('hola');
        $this->assertFalse($saludo->json('data.requiere_titulo'));

        $uuid = $saludo->json('data.uuid_conversacion');
        $exitoso = $this->chat('¿cuántas aves hay?', ['uuid_conversacion' => $uuid]);
        $this->assertTrue($exitoso->json('data.requiere_titulo'));

        ConversacionAsistente::where('uuid', $uuid)->update(['nombre' => 'Aves activas', 'nombre_origen' => 'automatico']);
        $otro = $this->chat('¿y gallinas?', ['uuid_conversacion' => $uuid]);
        $this->assertFalse($otro->json('data.requiere_titulo'));
    }

    public function test_la_respuesta_no_expone_la_clave_resultado_ni_ids(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);

        $r = $this->chat('¿cuántas aves hay?')->assertOk();

        $this->assertArrayNotHasKey('resultado', $r->json('data'));
        $this->assertSame(
            ['herramientas_utilizadas', 'requiere_titulo', 'respuesta', 'sugerencias_modulos', 'uuid_conversacion'],
            collect(array_keys($r->json('data')))->sort()->values()->all()
        );
        $this->assertStringNotContainsString((string) $usuario->email, $r->getContent());
    }

    public function test_la_auditoria_usa_un_seudonimo_estable_distinto_del_uuid_de_la_conversacion(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $uuid = $this->chat('¿cuántas aves hay?')->json('data.uuid_conversacion');
        $this->chat('¿y gallinas?', ['uuid_conversacion' => $uuid]);

        $logs = FuncionLogAgent::orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($logs[0]->uuid_conversacion, $logs[1]->uuid_conversacion, 'Mismo seudónimo en toda la conversación');
        $this->assertNotSame($uuid, $logs[0]->uuid_conversacion, 'No guarda el uuid de la conversación');
        $conversacion = ConversacionAsistente::where('uuid', $uuid)->firstOrFail();
        $this->assertSame(app(ConversacionAsistenteService::class)->uuidAuditoria($conversacion), $logs[0]->uuid_conversacion);
    }

    public function test_la_auditoria_no_referencia_al_usuario_ni_a_las_conversaciones(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $this->chat('¿cuántas aves hay?');

        $columnas = Schema::getColumnListing('mnt_funcion_log_agent');
        $this->assertNotContains('id_usuario', $columnas);
        $this->assertNotContains('id_conversacion_asistente', $columnas);
        $fila = json_encode(FuncionLogAgent::firstOrFail()->toArray());
        $conversacion = ConversacionAsistente::firstOrFail();
        $this->assertStringNotContainsString($conversacion->uuid, $fila);
    }

    public function test_un_fallo_de_la_capa_de_conversacion_no_rompe_el_chat(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $this->app->bind(ConversacionAsistenteService::class, fn () => new class extends ConversacionAsistenteService
        {
            public function obtenerOCrear(int $idUsuario, ?string $uuid): ConversacionAsistente
            {
                throw new RuntimeException('bd de conversaciones caída');
            }
        });

        $r = $this->chat('¿cuántas aves hay?')->assertOk();

        $this->assertSame('Hay 12 aves activas.', $r->json('data.respuesta'));
        $this->assertNull($r->json('data.uuid_conversacion'));
        $this->assertFalse($r->json('data.requiere_titulo'));
        $this->assertSame(0, MensajeAsistente::count());
    }

    public function test_un_fallo_al_guardar_el_turno_no_rompe_el_chat(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $this->app->bind(ConversacionAsistenteService::class, fn () => new class extends ConversacionAsistenteService
        {
            public function registrarTurno(ConversacionAsistente $conversacion, string $mensajeUsuario, string $textoModelo, string $resultado, array $herramientas = [], array $sugerencias = []): void
            {
                throw new RuntimeException('no se pudo guardar');
            }
        });

        $r = $this->chat('¿cuántas aves hay?')->assertOk();

        $this->assertSame('Hay 12 aves activas.', $r->json('data.respuesta'));
        $this->assertSame(0, MensajeAsistente::count());
    }

    public function test_el_contexto_respeta_el_limite_configurado(): void
    {
        config(['asistente.conversacion.contexto_mensajes' => 2]);
        $this->usuario();
        // Http::fake no se puede redefinir (la primera definición gana): una secuencia da una respuesta distinta por llamada
        Http::fake(['*' => Http::sequence()
            ->push($this->geminiTexto('respuesta uno'), 200)
            ->push($this->geminiTexto('respuesta dos'), 200)
            ->push($this->geminiTexto('respuesta tres'), 200),
        ]);
        $uuid = $this->chat('pregunta uno')->json('data.uuid_conversacion');
        $this->chat('pregunta dos', ['uuid_conversacion' => $uuid]);

        $this->chat('pregunta tres', ['uuid_conversacion' => $uuid])->assertOk();

        $recorded = Http::recorded();
        $texto = $this->textoEnviadoAGemini($recorded[count($recorded) - 1][0]);
        $this->assertStringContainsString('pregunta dos', $texto);
        $this->assertStringContainsString('respuesta dos', $texto);
        $this->assertStringNotContainsString('pregunta uno', $texto);
        $this->assertStringNotContainsString('respuesta uno', $texto);
    }

    // ------------------------------------------------- Endpoints de conversaciones (Task 4)

    private function conversacionDe(User $usuario, array $atributos = []): ConversacionAsistente
    {
        return ConversacionAsistente::create(array_merge([
            'id_usuario' => $usuario->id,
            'uuid' => (string) Str::uuid(),
            'ultima_actividad_at' => now(),
        ], $atributos));
    }

    public function test_los_endpoints_de_conversaciones_requieren_autenticacion(): void
    {
        $uuid = (string) Str::uuid();

        $this->getJson('/api/v1/asistente/conversaciones')->assertStatus(401);
        $this->getJson("/api/v1/asistente/conversaciones/{$uuid}")->assertStatus(401);
        $this->patchJson("/api/v1/asistente/conversaciones/{$uuid}", ['nombre' => 'x'])->assertStatus(401);
        $this->deleteJson("/api/v1/asistente/conversaciones/{$uuid}")->assertStatus(401);
    }

    public function test_listar_devuelve_solo_las_activas_del_usuario_con_su_forma(): void
    {
        $yo = $this->usuario();
        $otro = User::factory()->create();
        $mia = $this->conversacionDe($yo, ['nombre' => 'Aves activas']);
        $mia->mensajes()->create(['rol' => 'user', 'contenido' => '¿cuántas aves hay?', 'en_contexto' => true]);
        $this->conversacionDe($yo, ['estado' => ConversacionAsistente::ESTADO_ELIMINADA]);
        $this->conversacionDe($otro);

        $r = $this->getJson('/api/v1/asistente/conversaciones')->assertOk();

        $this->assertTrue($r->json('success'));
        $this->assertCount(1, $r->json('data'));
        $this->assertSame(['nombre', 'total_mensajes', 'ultima_actividad', 'uuid', 'vista_previa'], collect(array_keys($r->json('data.0')))->sort()->values()->all());
        $this->assertSame($mia->uuid, $r->json('data.0.uuid'));
        $this->assertSame('Aves activas', $r->json('data.0.nombre'));
        $this->assertSame('¿cuántas aves hay?', $r->json('data.0.vista_previa'));
    }

    public function test_listar_muestra_conversacion_como_nombre_de_las_que_no_tienen_nombre(): void
    {
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo);
        $c->mensajes()->create(['rol' => 'user', 'contenido' => 'hola', 'en_contexto' => false]);

        $r = $this->getJson('/api/v1/asistente/conversaciones')->assertOk();

        $this->assertSame('Conversación', $r->json('data.0.nombre'));
        $this->assertSame('hola', $r->json('data.0.vista_previa'));
    }

    public function test_abrir_devuelve_los_mensajes_en_orden_con_sus_banderas(): void
    {
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo, ['nombre' => 'Gastos']);
        $c->mensajes()->create(['rol' => 'user', 'contenido' => '¿cuánto gasté?', 'en_contexto' => true]);
        $c->mensajes()->create(['rol' => 'model', 'contenido' => 'Gastaste $50.', 'herramientas' => ['consultar_gastos_periodo'], 'sugerencias_modulos' => [['modulo' => 'gastos']], 'en_contexto' => true]);

        $r = $this->getJson("/api/v1/asistente/conversaciones/{$c->uuid}")->assertOk();

        $this->assertSame($c->uuid, $r->json('data.uuid'));
        $this->assertSame('Gastos', $r->json('data.nombre'));
        $this->assertSame(['user', 'model'], array_column($r->json('data.mensajes'), 'rol'));
        $this->assertSame(['consultar_gastos_periodo'], $r->json('data.mensajes.1.herramientas'));
        $this->assertSame([['modulo' => 'gastos']], $r->json('data.mensajes.1.sugerencias_modulos'));
        $this->assertFalse($r->json('data.mensajes.0.es_error'));
    }

    public function test_abrir_limita_a_los_ultimos_mensajes_configurados(): void
    {
        config(['asistente.conversacion.mensajes_max_carga' => 3]);
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo);
        foreach (range(1, 6) as $i) {
            $c->mensajes()->create(['rol' => $i % 2 ? 'user' : 'model', 'contenido' => "m{$i}", 'en_contexto' => true]);
        }

        $r = $this->getJson("/api/v1/asistente/conversaciones/{$c->uuid}")->assertOk();

        $this->assertSame(['m4', 'm5', 'm6'], array_column($r->json('data.mensajes'), 'contenido'));
    }

    public function test_un_usuario_no_abre_renombra_ni_elimina_la_conversacion_de_otro(): void
    {
        $dueno = User::factory()->create();
        $ajena = $this->conversacionDe($dueno, ['nombre' => 'Privada']);
        $ajena->mensajes()->create(['rol' => 'user', 'contenido' => 'contenido privado', 'en_contexto' => true]);
        $this->usuario();

        $this->getJson("/api/v1/asistente/conversaciones/{$ajena->uuid}")->assertStatus(404);
        $this->patchJson("/api/v1/asistente/conversaciones/{$ajena->uuid}", ['nombre' => 'Hackeada'])->assertStatus(404);
        $this->deleteJson("/api/v1/asistente/conversaciones/{$ajena->uuid}")->assertStatus(404);

        $fresca = $ajena->fresh();
        $this->assertSame('Privada', $fresca->nombre);
        $this->assertSame(ConversacionAsistente::ESTADO_ACTIVA, $fresca->estado);
        $this->assertNull($fresca->eliminada_at);
    }

    public function test_el_404_es_identico_para_ajena_inexistente_invalida_y_eliminada(): void
    {
        $dueno = User::factory()->create();
        $ajena = $this->conversacionDe($dueno);
        $yo = $this->usuario();
        $eliminada = $this->conversacionDe($yo, ['estado' => ConversacionAsistente::ESTADO_ELIMINADA]);
        $casos = [$ajena->uuid, (string) Str::uuid(), 'no-es-uuid', $eliminada->uuid];

        foreach ($casos as $uuid) {
            foreach (['getJson' => [], 'patchJson' => ['nombre' => 'x'], 'deleteJson' => []] as $metodo => $cuerpo) {
                $r = $this->{$metodo}("/api/v1/asistente/conversaciones/{$uuid}", $cuerpo);
                $r->assertStatus(404);
                $this->assertSame(['success' => false, 'message' => 'Conversación no encontrada.'], $r->json(), "{$metodo} {$uuid}");
            }
        }
    }

    public function test_eliminar_no_usa_la_palabra_quitar_en_la_respuesta(): void
    {
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo);

        $r = $this->deleteJson("/api/v1/asistente/conversaciones/{$c->uuid}")->assertOk();

        $this->assertSame('Conversación eliminada del historial.', $r->json('message'));
        $this->assertStringNotContainsStringIgnoringCase('quit', $r->json('message'));
    }

    public function test_eliminar_cambia_el_estado_sin_borrar_filas(): void
    {
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo);
        $c->mensajes()->create(['rol' => 'user', 'contenido' => 'hola', 'en_contexto' => true]);
        $c->mensajes()->create(['rol' => 'model', 'contenido' => '¡Hola!', 'en_contexto' => true]);

        $r = $this->deleteJson("/api/v1/asistente/conversaciones/{$c->uuid}")->assertOk();

        $this->assertTrue($r->json('success'));
        $this->assertSame('Conversación eliminada del historial.', $r->json('message'));
        $fresca = ConversacionAsistente::find($c->id);
        $this->assertNotNull($fresca, 'La conversación se conserva');
        $this->assertSame(ConversacionAsistente::ESTADO_ELIMINADA, $fresca->estado);
        $this->assertNotNull($fresca->eliminada_at);
        $this->assertSame(2, MensajeAsistente::where('id_conversacion_asistente', $c->id)->count(), 'Los mensajes se conservan');
    }

    public function test_una_eliminada_no_se_lista_no_se_abre_y_el_chat_con_su_uuid_crea_una_nueva(): void
    {
        $yo = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $uuid = $this->chat('¿cuántas aves hay?')->json('data.uuid_conversacion');
        $this->deleteJson("/api/v1/asistente/conversaciones/{$uuid}")->assertOk();

        $this->assertCount(0, $this->getJson('/api/v1/asistente/conversaciones')->json('data'));
        $this->getJson("/api/v1/asistente/conversaciones/{$uuid}")->assertStatus(404);

        $nuevo = $this->chat('¿y gallinas?', ['uuid_conversacion' => $uuid])->assertOk()->json('data.uuid_conversacion');
        $this->assertNotSame($uuid, $nuevo);
        $this->assertSame(2, ConversacionAsistente::where('id_usuario', $yo->id)->count());
        $this->assertSame(2, MensajeAsistente::where('id_conversacion_asistente', ConversacionAsistente::where('uuid', $uuid)->value('id'))->count(), 'La eliminada no recibió mensajes');
    }

    public function test_renombrar_valida_longitud_y_saltos_de_linea_y_marca_origen_manual(): void
    {
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo);
        $url = "/api/v1/asistente/conversaciones/{$c->uuid}";

        $this->patchJson($url, ['nombre' => ''])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        $this->patchJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        $this->patchJson($url, ['nombre' => str_repeat('a', 61)])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        $this->patchJson($url, ['nombre' => "dos\nlineas"])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        $this->patchJson($url, ['nombre' => '   '])->assertStatus(422)->assertJsonValidationErrors(['nombre']);
        $this->assertNull($c->fresh()->nombre);

        $ok = $this->patchJson($url, ['nombre' => '  Postura de la semana  '])->assertOk();

        $this->assertSame('Postura de la semana', $ok->json('data.nombre'));
        $this->assertSame($c->uuid, $ok->json('data.uuid'));
        $fresca = $c->fresh();
        $this->assertSame('Postura de la semana', $fresca->nombre);
        $this->assertSame(ConversacionAsistente::NOMBRE_MANUAL, $fresca->nombre_origen);
    }

    public function test_un_nombre_con_html_se_guarda_como_texto_literal(): void
    {
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo);

        $this->patchJson("/api/v1/asistente/conversaciones/{$c->uuid}", ['nombre' => '<b>x</b>'])->assertOk();

        $this->assertSame('<b>x</b>', $c->fresh()->nombre);
    }

    public function test_las_respuestas_no_incluyen_ids_ni_datos_de_usuario(): void
    {
        $yo = $this->usuario();
        $c = $this->conversacionDe($yo, ['nombre' => 'Aves']);
        $c->mensajes()->create(['rol' => 'user', 'contenido' => 'hola', 'en_contexto' => true]);

        foreach (['/api/v1/asistente/conversaciones', "/api/v1/asistente/conversaciones/{$c->uuid}"] as $ruta) {
            $json = $this->getJson($ruta)->assertOk()->getContent();
            $this->assertStringNotContainsString('id_usuario', $json, $ruta);
            $this->assertStringNotContainsString((string) $yo->email, $json, $ruta);
            $this->assertStringNotContainsString('id_conversacion_asistente', $json, $ruta);
        }
    }

    public function test_el_benchmark_no_incluye_datos_de_conversaciones(): void
    {
        $yo = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);
        $uuid = $this->chat('mensaje exclusivo de la conversacion')->json('data.uuid_conversacion');
        ConversacionAsistente::where('uuid', $uuid)->update(['nombre' => 'Nombre privado del usuario']);

        foreach (['estadisticas', 'logs'] as $ruta) {
            $json = $this->getJson("/api/v1/asistente/benchmark/{$ruta}")->assertOk()->getContent();
            $this->assertStringNotContainsString($uuid, $json);
            $this->assertStringNotContainsString('Nombre privado del usuario', $json);
        }
        $idLog = FuncionLogAgent::firstOrFail()->id;
        $detalle = $this->getJson("/api/v1/asistente/benchmark/logs/{$idLog}")->assertOk()->getContent();
        $this->assertStringNotContainsString($uuid, $detalle);
        $this->assertStringNotContainsString((string) $yo->email, $detalle);
    }

    public function test_un_uuid_con_formato_invalido_no_da_error_y_crea_una_conversacion(): void
    {
        $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Hay 12 aves activas.'), 200)]);

        $r = $this->chat('¿cuántas aves hay?', ['uuid_conversacion' => 'no-es-un-uuid'])->assertOk();

        $this->assertTrue(Str::isUuid($r->json('data.uuid_conversacion')));
        $this->assertSame(1, ConversacionAsistente::count());
    }
}
