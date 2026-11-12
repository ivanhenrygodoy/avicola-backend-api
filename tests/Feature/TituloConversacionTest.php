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
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TituloConversacionTest extends TestCase
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
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $texto]]]]]];
    }

    private function usuario(): User
    {
        $usuario = User::factory()->create();
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    /**
     * Conversación con un turno exitoso (la consulta y la respuesta que se usan para nombrarla).
     */
    private function conversacionConTurno(User $usuario, string $consulta = '¿Cuántos gastos tuvimos en noviembre?', string $respuesta = 'En noviembre se registraron 5 gastos por $182.50.'): ConversacionAsistente
    {
        $c = ConversacionAsistente::create(['id_usuario' => $usuario->id, 'uuid' => (string) Str::uuid(), 'ultima_actividad_at' => now()]);
        app(ConversacionAsistenteService::class)->registrarTurno($c, $consulta, $respuesta, 'exitoso');

        return $c->fresh();
    }

    private function pedirTitulo(ConversacionAsistente $c)
    {
        return $this->postJson("/api/v1/asistente/conversaciones/{$c->uuid}/titulo");
    }

    public function test_genera_el_nombre_con_gemini_y_lo_guarda_como_automatico(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Gastos de noviembre'), 200)]);
        $c = $this->conversacionConTurno($usuario);

        $r = $this->pedirTitulo($c)->assertOk();

        $this->assertSame('Gastos de noviembre', $r->json('data.nombre'));
        $fresca = $c->fresh();
        $this->assertSame('Gastos de noviembre', $fresca->nombre);
        $this->assertSame(ConversacionAsistente::NOMBRE_AUTOMATICO, $fresca->nombre_origen);
        Http::assertSentCount(1);
    }

    public function test_solo_envia_la_primera_consulta_y_respuesta_truncadas_y_sin_herramientas(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Resumen de gastos'), 200)]);
        $c = ConversacionAsistente::create(['id_usuario' => $usuario->id, 'uuid' => (string) Str::uuid(), 'ultima_actividad_at' => now()]);
        $servicio = app(ConversacionAsistenteService::class);
        $servicio->registrarTurno($c, str_repeat('Q', 500), str_repeat('R', 500), 'exitoso');
        $servicio->registrarTurno($c, 'SEGUNDA CONSULTA QUE NO DEBE ENVIARSE', 'SEGUNDA RESPUESTA', 'exitoso');

        $this->pedirTitulo($c)->assertOk();

        Http::assertSent(function (Request $request) {
            $datos = $request->data();
            $texto = json_encode($datos['contents'] ?? [], JSON_UNESCAPED_UNICODE);

            return ! array_key_exists('tools', $datos)
                && substr_count($texto, 'Q') === 300
                && substr_count($texto, 'R') >= 300 && substr_count($texto, 'R') < 320
                && ! str_contains($texto, 'SEGUNDA')
                && isset($datos['system_instruction']);
        });
    }

    public function test_no_envia_el_prompt_del_sistema_del_chat(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Gastos'), 200)]);
        $c = $this->conversacionConTurno($usuario);

        $this->pedirTitulo($c)->assertOk();

        Http::assertSent(function (Request $request) {
            $instruccion = json_encode($request->data()['system_instruction'] ?? [], JSON_UNESCAPED_UNICODE);

            return ! str_contains($instruccion, 'Eres AviaCore')
                && str_contains(mb_strtolower($instruccion), 'título');
        });
    }

    public function test_si_gemini_falla_usa_el_respaldo_y_no_reintenta(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response('{"error":{"code":503}}', 503)]);
        $c = $this->conversacionConTurno($usuario, '¿Cuántos gastos tuvimos en noviembre?');

        $primera = $this->pedirTitulo($c)->assertOk();

        $this->assertSame('¿Cuántos gastos tuvimos en noviembre?', $primera->json('data.nombre'));
        $this->assertSame(ConversacionAsistente::NOMBRE_AUTOMATICO, $c->fresh()->nombre_origen);
        $llamadas = count(Http::recorded());

        $segunda = $this->pedirTitulo($c)->assertOk();

        $this->assertSame($primera->json('data.nombre'), $segunda->json('data.nombre'));
        $this->assertCount($llamadas, Http::recorded(), 'Un 503 no genera reintentos');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function respuestasInservibles(): array
    {
        return [
            'vacia' => [''],
            'solo espacios' => ['   '],
            'nombre de tabla' => ['Gastos de mnt_gastos'],
            'ruta de codigo' => ['Revisar app/Models/Gasto'],
            'demasiado larga' => [str_repeat('palabra ', 30)],
        ];
    }

    #[DataProvider('respuestasInservibles')]
    public function test_respuesta_vacia_larga_o_con_datos_internos_cae_en_el_respaldo(string $devuelto): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto($devuelto), 200)]);
        $c = $this->conversacionConTurno($usuario, '¿Cuántos huevos se recolectaron ayer?');

        $r = $this->pedirTitulo($c)->assertOk();

        $this->assertSame('¿Cuántos huevos se recolectaron ayer?', $r->json('data.nombre'));
        $this->assertSame(ConversacionAsistente::NOMBRE_AUTOMATICO, $c->fresh()->nombre_origen);
    }

    public function test_el_nombre_queda_en_una_linea_sin_comillas(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto("\"Gastos de\nnoviembre\"."), 200)]);
        $c = $this->conversacionConTurno($usuario);

        $r = $this->pedirTitulo($c)->assertOk();

        $nombre = $r->json('data.nombre');
        $this->assertStringNotContainsString("\n", $nombre);
        $this->assertStringNotContainsString('"', $nombre);
        $this->assertStringNotContainsString("'", $nombre);
        $this->assertSame('Gastos de noviembre', $nombre);
    }

    public function test_un_nombre_manual_nunca_se_pisa(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Nombre de Gemini'), 200)]);
        $c = $this->conversacionConTurno($usuario);
        app(ConversacionAsistenteService::class)->renombrar($c, 'Mi nombre');

        $r = $this->pedirTitulo($c)->assertOk();

        $this->assertSame('Mi nombre', $r->json('data.nombre'));
        $this->assertSame('Mi nombre', $c->fresh()->nombre);
        $this->assertSame(ConversacionAsistente::NOMBRE_MANUAL, $c->fresh()->nombre_origen);
        Http::assertNothingSent();
    }

    public function test_un_renombrado_manual_durante_la_llamada_al_modelo_no_se_pisa(): void
    {
        $usuario = $this->usuario();
        $c = $this->conversacionConTurno($usuario);
        // El usuario renombra mientras Gemini todavía está respondiendo.
        Http::fake(function () use ($c) {
            app(ConversacionAsistenteService::class)->renombrar(ConversacionAsistente::find($c->id), 'Mi nombre');

            return Http::response($this->geminiTexto('Nombre de Gemini'), 200);
        });

        $r = $this->pedirTitulo($c)->assertOk();

        $fresca = $c->fresh();
        $this->assertSame('Mi nombre', $fresca->nombre);
        $this->assertSame(ConversacionAsistente::NOMBRE_MANUAL, $fresca->nombre_origen);
        $this->assertSame('Mi nombre', $r->json('data.nombre'));
    }

    public function test_el_nombre_de_respaldo_cortado_no_corrompe_caracteres_acentuados(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response('{"error":{"code":503}}', 503)]);
        // Sin espacios, el corte cae justo después de la «á» (50 caracteres).
        $c = $this->conversacionConTurno($usuario, str_repeat('a', 49).'ábbb');

        $nombre = $this->pedirTitulo($c)->assertOk()->json('data.nombre');

        $this->assertTrue(mb_check_encoding($nombre, 'UTF-8'), 'El nombre debe ser UTF-8 válido');
        $this->assertSame(str_repeat('a', 49).'á', $nombre);
    }

    public function test_el_nombre_de_gemini_con_letra_final_acentuada_no_se_corrompe(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('"Ciclo de Noë."'), 200)]);
        $c = $this->conversacionConTurno($usuario);

        $nombre = $this->pedirTitulo($c)->assertOk()->json('data.nombre');

        $this->assertSame('Ciclo de Noë', $nombre);
    }

    public function test_un_nombre_automatico_ya_generado_no_se_vuelve_a_pedir(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Primer nombre'), 200)]);
        $c = $this->conversacionConTurno($usuario);
        $this->pedirTitulo($c)->assertOk();
        $llamadasTrasElPrimero = count(Http::recorded());

        // Http::fake no se puede redefinir (la primera definición gana): lo que importa es que no haya
        // una segunda llamada al modelo, y que el nombre guardado siga siendo el primero.
        $r = $this->pedirTitulo($c)->assertOk();

        $this->assertSame('Primer nombre', $r->json('data.nombre'));
        $this->assertSame(1, $llamadasTrasElPrimero);
        $this->assertCount($llamadasTrasElPrimero, Http::recorded(), 'No se vuelve a llamar al modelo');
    }

    public function test_sin_turnos_exitosos_no_llama_al_modelo_ni_cambia_el_estado(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('No debería usarse'), 200)]);
        $c = ConversacionAsistente::create(['id_usuario' => $usuario->id, 'uuid' => (string) Str::uuid(), 'ultima_actividad_at' => now()]);
        $servicio = app(ConversacionAsistenteService::class);
        $servicio->registrarTurno($c, 'hola', '¡Hola!', 'fast_path');
        $servicio->registrarTurno($c, 'suma 2+3', 'No realizo cálculos', 'bloqueado_guard');
        $servicio->registrarTurno($c, '¿cuántas aves hay?', 'servicio caído', 'error');

        $r = $this->pedirTitulo($c)->assertOk();

        $this->assertSame('Conversación', $r->json('data.nombre'));
        $this->assertNull($c->fresh()->nombre);
        $this->assertSame(ConversacionAsistente::NOMBRE_PENDIENTE, $c->fresh()->nombre_origen, 'Sigue pendiente: podrá nombrarse tras un turno exitoso');
        Http::assertNothingSent();
    }

    public function test_el_nombre_usa_el_primer_turno_exitoso_aunque_antes_haya_un_saludo(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Aves activas'), 200)]);
        $c = ConversacionAsistente::create(['id_usuario' => $usuario->id, 'uuid' => (string) Str::uuid(), 'ultima_actividad_at' => now()]);
        $servicio = app(ConversacionAsistenteService::class);
        $servicio->registrarTurno($c, 'hola', '¡Hola!', 'fast_path');
        $servicio->registrarTurno($c, '¿Cuántas aves activas hay?', 'Hay 14 aves activas.', 'exitoso');

        $this->pedirTitulo($c)->assertOk();

        Http::assertSent(function (Request $request) {
            $texto = json_encode($request->data()['contents'] ?? [], JSON_UNESCAPED_UNICODE);

            return str_contains($texto, '¿Cuántas aves activas hay?') && ! str_contains($texto, 'hola');
        });
        $this->assertSame('Aves activas', $c->fresh()->nombre);
    }

    public function test_el_endpoint_da_404_para_conversaciones_ajenas_inexistentes_o_eliminadas(): void
    {
        $dueno = User::factory()->create();
        $ajena = $this->conversacionConTurno($dueno);
        $yo = $this->usuario();
        $eliminada = $this->conversacionConTurno($yo);
        $eliminada->update(['estado' => ConversacionAsistente::ESTADO_ELIMINADA]);
        Http::fake(['*' => Http::response($this->geminiTexto('No debería usarse'), 200)]);

        foreach ([$ajena->uuid, (string) Str::uuid(), 'no-es-uuid', $eliminada->uuid] as $uuid) {
            $r = $this->postJson("/api/v1/asistente/conversaciones/{$uuid}/titulo");
            $r->assertStatus(404);
            $this->assertSame(['success' => false, 'message' => 'Conversación no encontrada.'], $r->json());
        }
        $this->assertNull($ajena->fresh()->nombre);
        Http::assertNothingSent();
    }

    public function test_el_endpoint_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/asistente/conversaciones/'.Str::uuid().'/titulo')->assertStatus(401);
    }

    public function test_el_endpoint_tiene_limite_de_peticiones(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Gastos'), 200)]);
        $c = $this->conversacionConTurno($usuario);

        foreach (range(1, 10) as $i) {
            $this->pedirTitulo($c)->assertOk();
        }

        $this->pedirTitulo($c)->assertStatus(429);
    }

    public function test_la_llamada_de_nombrado_no_se_audita_en_el_benchmark(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::response($this->geminiTexto('Gastos'), 200)]);
        $c = $this->conversacionConTurno($usuario);

        $this->pedirTitulo($c)->assertOk();

        $this->assertSame(0, FuncionLogAgent::count());
    }

    public function test_el_chat_indica_cuando_pedir_el_nombre_y_el_flujo_completo_lo_deja_listado(): void
    {
        $usuario = $this->usuario();
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Hay 12 aves activas.']]]]], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 5]], 200)
            ->push($this->geminiTexto('Aves activas'), 200),
        ]);

        $chat = $this->postJson('/api/v1/asistente/chat', ['mensaje' => '¿cuántas aves hay?'])->assertOk();
        $this->assertTrue($chat->json('data.requiere_titulo'));
        $uuid = $chat->json('data.uuid_conversacion');

        $this->postJson("/api/v1/asistente/conversaciones/{$uuid}/titulo")->assertOk();

        $lista = $this->getJson('/api/v1/asistente/conversaciones')->assertOk();
        $this->assertSame('Aves activas', $lista->json('data.0.nombre'));
        $this->assertSame(2, MensajeAsistente::count());
    }
}
