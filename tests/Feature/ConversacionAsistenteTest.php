<?php

namespace Tests\Feature;

use App\Models\ConversacionAsistente;
use App\Models\MensajeAsistente;
use App\Models\User;
use App\Services\Asistente\Conversacion\ConversacionAsistenteService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversacionAsistenteTest extends TestCase
{
    use RefreshDatabase;

    private function conversacion(?User $usuario = null, array $atributos = []): ConversacionAsistente
    {
        return ConversacionAsistente::create(array_merge([
            'id_usuario' => ($usuario ?? User::factory()->create())->id,
            'uuid' => (string) Str::uuid(),
            'ultima_actividad_at' => now(),
        ], $atributos));
    }

    public function test_un_usuario_puede_tener_varias_conversaciones(): void
    {
        $usuario = User::factory()->create();

        $this->conversacion($usuario);
        $this->conversacion($usuario);

        $this->assertSame(2, ConversacionAsistente::where('id_usuario', $usuario->id)->count());
    }

    public function test_el_uuid_es_unico(): void
    {
        $uuid = (string) Str::uuid();
        $this->conversacion(null, ['uuid' => $uuid]);

        $this->expectException(QueryException::class);
        $this->conversacion(null, ['uuid' => $uuid]);
    }

    public function test_valores_por_defecto_de_una_conversacion_nueva(): void
    {
        $c = $this->conversacion()->fresh();

        $this->assertSame(ConversacionAsistente::ESTADO_ACTIVA, $c->estado);
        $this->assertSame(ConversacionAsistente::NOMBRE_PENDIENTE, $c->nombre_origen);
        $this->assertNull($c->nombre);
        $this->assertNull($c->eliminada_at);
    }

    public function test_los_mensajes_se_leen_en_orden_con_sus_banderas_y_json(): void
    {
        $c = $this->conversacion();

        $c->mensajes()->create(['rol' => MensajeAsistente::ROL_MODELO, 'contenido' => 'segundo', 'herramientas' => ['consultar_gastos_periodo'], 'sugerencias_modulos' => [['modulo' => 'gastos']], 'es_error' => false, 'en_contexto' => true]);
        $c->mensajes()->create(['rol' => MensajeAsistente::ROL_USUARIO, 'contenido' => 'tercero', 'es_error' => true, 'en_contexto' => false]);

        $mensajes = $c->fresh()->mensajes;

        // Orden de inserción (por id), no alfabético ni por rol
        $this->assertSame(['segundo', 'tercero'], $mensajes->pluck('contenido')->all());
        $this->assertSame(['consultar_gastos_periodo'], $mensajes[0]->herramientas);
        $this->assertSame([['modulo' => 'gastos']], $mensajes[0]->sugerencias_modulos);
        $this->assertFalse($mensajes[0]->es_error);
        $this->assertTrue($mensajes[0]->en_contexto);
        $this->assertTrue($mensajes[1]->es_error);
        $this->assertFalse($mensajes[1]->en_contexto);
        $this->assertSame('model', $mensajes[0]->rol);
        $this->assertSame('user', $mensajes[1]->rol);
    }

    public function test_un_mensaje_nuevo_entra_al_contexto_y_sin_error_por_defecto(): void
    {
        $m = $this->conversacion()->mensajes()->create(['rol' => MensajeAsistente::ROL_USUARIO, 'contenido' => 'hola'])->fresh();

        $this->assertTrue($m->en_contexto);
        $this->assertFalse($m->es_error);
        $this->assertNull($m->herramientas);
    }

    public function test_no_se_puede_borrar_una_conversacion_con_mensajes(): void
    {
        $c = $this->conversacion();
        $c->mensajes()->create(['rol' => MensajeAsistente::ROL_USUARIO, 'contenido' => 'hola']);

        $this->expectException(QueryException::class);
        $c->delete();
    }

    public function test_no_se_puede_borrar_un_usuario_con_conversaciones(): void
    {
        $usuario = User::factory()->create();
        $this->conversacion($usuario);

        $this->expectException(QueryException::class);
        $usuario->delete();
    }

    // ---------------------------------------------------------------- Servicio (Task 2)

    private function servicio(): ConversacionAsistenteService
    {
        return app(ConversacionAsistenteService::class);
    }

    /**
     * Agrega un turno a mano (sin pasar por el servicio) para controlar fechas y banderas.
     *
     * @param  array<string, mixed>  $extra
     */
    private function mensaje(ConversacionAsistente $c, string $rol, string $contenido, bool $enContexto = true, array $extra = []): MensajeAsistente
    {
        return $c->mensajes()->create(array_merge([
            'rol' => $rol,
            'contenido' => $contenido,
            'en_contexto' => $enContexto,
        ], $extra));
    }

    public function test_buscar_solo_devuelve_conversaciones_activas_del_usuario(): void
    {
        $dueno = User::factory()->create();
        $otro = User::factory()->create();
        $propia = $this->conversacion($dueno);
        $ajena = $this->conversacion($otro);
        $eliminada = $this->conversacion($dueno, ['estado' => ConversacionAsistente::ESTADO_ELIMINADA]);

        $this->assertSame($propia->id, $this->servicio()->buscar($dueno->id, $propia->uuid)?->id);
        $this->assertNull($this->servicio()->buscar($dueno->id, $ajena->uuid));
        $this->assertNull($this->servicio()->buscar($dueno->id, $eliminada->uuid));
        $this->assertNull($this->servicio()->buscar($dueno->id, (string) Str::uuid()));
        $this->assertNull($this->servicio()->buscar($dueno->id, 'no-es-un-uuid'));
        $this->assertNull($this->servicio()->buscar($dueno->id, "' OR 1=1 --"));
        $this->assertNull($this->servicio()->buscar($dueno->id, null));
        $this->assertNull($this->servicio()->buscar($dueno->id, ''));
    }

    public function test_obtener_o_crear_con_uuid_ajeno_crea_una_nueva_y_no_toca_la_ajena(): void
    {
        $dueno = User::factory()->create();
        $intruso = User::factory()->create();
        $ajena = $this->conversacion($dueno);

        $nueva = $this->servicio()->obtenerOCrear($intruso->id, $ajena->uuid);

        $this->assertNotSame($ajena->id, $nueva->id);
        $this->assertNotSame($ajena->uuid, $nueva->uuid);
        $this->assertSame($intruso->id, $nueva->id_usuario);
        $this->assertSame($dueno->id, $ajena->fresh()->id_usuario);
    }

    public function test_obtener_o_crear_reutiliza_la_conversacion_propia_y_crea_con_uuid_generado_por_el_servidor(): void
    {
        $usuario = User::factory()->create();
        $existente = $this->conversacion($usuario);

        $this->assertSame($existente->id, $this->servicio()->obtenerOCrear($usuario->id, $existente->uuid)->id);

        $creada = $this->servicio()->obtenerOCrear($usuario->id, null);
        $this->assertTrue(Str::isUuid($creada->uuid));
        $this->assertNotNull($creada->ultima_actividad_at);
        $this->assertSame(2, ConversacionAsistente::where('id_usuario', $usuario->id)->count());
    }

    public function test_una_conversacion_recien_creada_ya_trae_estado_y_origen_del_nombre_en_memoria(): void
    {
        // Sin leerla de vuelta de la base: el chat decide `requiere_titulo` con el modelo que acaba de crear.
        $nueva = $this->servicio()->obtenerOCrear(User::factory()->create()->id, null);

        $this->assertSame(ConversacionAsistente::NOMBRE_PENDIENTE, $nueva->nombre_origen);
        $this->assertSame(ConversacionAsistente::ESTADO_ACTIVA, $nueva->estado);
        $this->assertNull($nueva->nombre);
    }

    public function test_el_contexto_toma_los_ultimos_n_y_empieza_con_el_usuario(): void
    {
        config(['asistente.conversacion.contexto_mensajes' => 6]);
        $c = $this->conversacion();
        foreach (range(1, 5) as $i) {
            $this->mensaje($c, MensajeAsistente::ROL_USUARIO, "u{$i}");
            $this->mensaje($c, MensajeAsistente::ROL_MODELO, "m{$i}");
        }

        $contexto = $this->servicio()->contexto($c);

        $this->assertCount(6, $contexto);
        $this->assertSame('user', $contexto[0]['role']);
        $this->assertSame('u3', $contexto[0]['parts'][0]['text']);
        $this->assertSame('m5', $contexto[5]['parts'][0]['text']);
        $this->assertSame(['user', 'model', 'user', 'model', 'user', 'model'], array_column($contexto, 'role'));
    }

    public function test_con_un_limite_impar_el_contexto_descarta_el_mensaje_inicial_del_modelo(): void
    {
        config(['asistente.conversacion.contexto_mensajes' => 5]);
        $c = $this->conversacion();
        foreach (range(1, 5) as $i) {
            $this->mensaje($c, MensajeAsistente::ROL_USUARIO, "u{$i}");
            $this->mensaje($c, MensajeAsistente::ROL_MODELO, "m{$i}");
        }

        $contexto = $this->servicio()->contexto($c);

        // Los últimos 5 son m3,u4,m4,u5,m5: el primero es del modelo y se descarta
        $this->assertSame('user', $contexto[0]['role']);
        $this->assertSame(['u4', 'm4', 'u5', 'm5'], array_map(fn ($m) => $m['parts'][0]['text'], $contexto));
    }

    public function test_el_contexto_no_tiene_ventana_de_tiempo(): void
    {
        $c = $this->conversacion();
        $this->mensaje($c, MensajeAsistente::ROL_USUARIO, 'pregunta vieja', true, ['created_at' => now()->subDays(30)]);
        $this->mensaje($c, MensajeAsistente::ROL_MODELO, 'respuesta vieja', true, ['created_at' => now()->subDays(30)]);

        $contexto = $this->servicio()->contexto($c);

        $this->assertCount(2, $contexto);
        $this->assertSame('pregunta vieja', $contexto[0]['parts'][0]['text']);
    }

    public function test_saludo_bloqueo_y_error_no_entran_al_contexto(): void
    {
        $c = $this->conversacion();
        $this->servicio()->registrarTurno($c, 'hola', '¡Hola!', 'fast_path');
        $this->servicio()->registrarTurno($c, 'suma 2+3', 'No realizo cálculos', 'bloqueado_guard');
        $this->servicio()->registrarTurno($c, '¿cuántas aves hay?', 'servicio caído', 'error');
        $this->servicio()->registrarTurno($c, '¿cuántos gastos hubo?', 'Hubo 3 gastos.', 'exitoso');

        $contexto = $this->servicio()->contexto($c);

        $this->assertSame(['¿cuántos gastos hubo?', 'Hubo 3 gastos.'], array_map(fn ($m) => $m['parts'][0]['text'], $contexto));
    }

    public function test_sin_mensajes_validos_el_contexto_es_vacio(): void
    {
        $c = $this->conversacion();
        $this->assertSame([], $this->servicio()->contexto($c));

        $this->servicio()->registrarTurno($c, 'hola', '¡Hola!', 'fast_path');
        $this->assertSame([], $this->servicio()->contexto($c));
    }

    public function test_registrar_turno_guarda_ambos_mensajes_en_orden_con_banderas_y_json(): void
    {
        $c = $this->conversacion(null, ['ultima_actividad_at' => now()->subDay()]);

        $this->servicio()->registrarTurno($c, '¿cuántos gastos?', 'Hubo 3.', 'exitoso', ['consultar_gastos_periodo'], [['modulo' => 'gastos']]);

        $mensajes = $c->fresh()->mensajes;
        $this->assertCount(2, $mensajes);
        $this->assertSame(['user', 'model'], $mensajes->pluck('rol')->all());
        $this->assertSame(['¿cuántos gastos?', 'Hubo 3.'], $mensajes->pluck('contenido')->all());
        $this->assertSame([true, true], $mensajes->pluck('en_contexto')->all());
        $this->assertSame([false, false], $mensajes->pluck('es_error')->all());
        $this->assertSame(['consultar_gastos_periodo'], $mensajes[1]->herramientas);
        $this->assertSame([['modulo' => 'gastos']], $mensajes[1]->sugerencias_modulos);
        $this->assertTrue($c->fresh()->ultima_actividad_at->greaterThan(now()->subMinute()));
    }

    public function test_un_turno_con_error_se_guarda_marcado_y_fuera_del_contexto_en_ambos_mensajes(): void
    {
        $c = $this->conversacion();

        $this->servicio()->registrarTurno($c, '¿cuántas aves hay?', 'servicio caído', 'error');

        $mensajes = $c->fresh()->mensajes;
        $this->assertSame([true, true], $mensajes->pluck('es_error')->all());
        $this->assertSame([false, false], $mensajes->pluck('en_contexto')->all());
    }

    public function test_eliminar_solo_cambia_el_estado_y_conserva_los_mensajes(): void
    {
        $c = $this->conversacion();
        $this->servicio()->registrarTurno($c, 'pregunta', 'respuesta', 'exitoso');

        $this->servicio()->eliminar($c);

        $fresca = ConversacionAsistente::find($c->id);
        $this->assertNotNull($fresca, 'La conversación no se borra');
        $this->assertSame(ConversacionAsistente::ESTADO_ELIMINADA, $fresca->estado);
        $this->assertNotNull($fresca->eliminada_at);
        $this->assertSame(2, MensajeAsistente::where('id_conversacion_asistente', $c->id)->count());
        $this->assertNull($this->servicio()->buscar($c->id_usuario, $c->uuid));
    }

    public function test_listar_ordena_por_actividad_trunca_la_vista_previa_y_excluye_eliminadas(): void
    {
        config(['avicola.timezone' => 'America/El_Salvador']);
        $usuario = User::factory()->create();
        $vieja = $this->conversacion($usuario, ['ultima_actividad_at' => now()->subDays(2)]);
        $reciente = $this->conversacion($usuario, ['ultima_actividad_at' => now()->subHour(), 'nombre' => 'Gastos de noviembre']);
        $eliminada = $this->conversacion($usuario, ['ultima_actividad_at' => now(), 'estado' => ConversacionAsistente::ESTADO_ELIMINADA]);
        $this->conversacion(User::factory()->create(), ['ultima_actividad_at' => now()]); // de otro usuario
        $this->mensaje($vieja, MensajeAsistente::ROL_USUARIO, str_repeat('a', 200));
        $this->mensaje($vieja, MensajeAsistente::ROL_MODELO, 'respuesta');
        $this->mensaje($reciente, MensajeAsistente::ROL_USUARIO, 'hola');

        $lista = $this->servicio()->listar($usuario->id);

        $this->assertCount(2, $lista);
        $this->assertSame([$reciente->uuid, $vieja->uuid], array_column($lista, 'uuid'));
        $this->assertSame('Gastos de noviembre', $lista[0]['nombre']);
        $this->assertSame('hola', $lista[0]['vista_previa']);
        $this->assertSame(1, $lista[0]['total_mensajes']);
        $this->assertSame(2, $lista[1]['total_mensajes']);
        $this->assertLessThanOrEqual(93, mb_strlen($lista[1]['vista_previa']));
        $this->assertStringEndsWith('…', $lista[1]['vista_previa']);
        $this->assertMatchesRegularExpression('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2} (AM|PM)$/', $lista[0]['ultima_actividad']);
        $this->assertNotContains($eliminada->uuid, array_column($lista, 'uuid'));
        $this->assertArrayNotHasKey('id_usuario', $lista[0]);
        $this->assertArrayNotHasKey('id', $lista[0]);
    }

    public function test_listar_convierte_la_fecha_a_la_zona_de_negocio(): void
    {
        config(['avicola.timezone' => 'America/El_Salvador']);
        $usuario = User::factory()->create();
        // 03:30 UTC del 10 = 09:30 PM del 09 en El Salvador (UTC-6)
        $this->conversacion($usuario, ['ultima_actividad_at' => Carbon::parse('2026-11-10 03:30:00', 'UTC')]);

        $this->assertSame('09-11-2026 09:30 PM', $this->servicio()->listar($usuario->id)[0]['ultima_actividad']);
    }

    public function test_listar_respeta_el_maximo_configurado(): void
    {
        config(['asistente.conversacion.listado_max' => 3]);
        $usuario = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->conversacion($usuario, ['ultima_actividad_at' => now()->subMinutes($i)]);
        }

        $this->assertCount(3, $this->servicio()->listar($usuario->id));
    }

    public function test_mensajes_devuelve_los_ultimos_n_en_orden_ascendente_con_su_forma(): void
    {
        config(['asistente.conversacion.mensajes_max_carga' => 4]);
        $c = $this->conversacion();
        foreach (range(1, 6) as $i) {
            $this->mensaje($c, $i % 2 ? MensajeAsistente::ROL_USUARIO : MensajeAsistente::ROL_MODELO, "msg{$i}", true, ['created_at' => now()->subMinutes(10 - $i)]);
        }

        $mensajes = $this->servicio()->mensajes($c);

        $this->assertSame(['msg3', 'msg4', 'msg5', 'msg6'], array_column($mensajes, 'contenido'));
        $this->assertSame(['id', 'rol', 'contenido', 'herramientas', 'sugerencias_modulos', 'es_error', 'created_at'], array_keys($mensajes[0]));
    }

    public function test_nombre_visible_es_conversacion_cuando_no_hay_nombre_aunque_haya_mensajes(): void
    {
        $c = $this->conversacion();
        $this->assertSame('Conversación', $this->servicio()->nombreVisible($c));

        $this->servicio()->registrarTurno($c, 'hola, ¿cómo estás?', '¡Hola!', 'fast_path');
        $this->assertSame('Conversación', $this->servicio()->nombreVisible($c->fresh()));

        $c->update(['nombre' => 'Postura de la semana']);
        $this->assertSame('Postura de la semana', $this->servicio()->nombreVisible($c->fresh()));
    }

    public function test_respaldo_de_nombre_usa_el_comienzo_de_la_consulta_corta_en_palabra_y_tolera_conversacion_sin_mensajes(): void
    {
        $vacia = $this->conversacion();
        $this->assertSame('Conversación', $this->servicio()->respaldoDeNombre($vacia));

        $c = $this->conversacion();
        $this->mensaje($c, MensajeAsistente::ROL_USUARIO, 'Quiero saber cuántas gallinas ponedoras tengo actualmente en la casita número uno');
        $respaldo = $this->servicio()->respaldoDeNombre($c);

        $this->assertLessThanOrEqual(50, mb_strlen($respaldo));
        $this->assertStringStartsWith('Quiero saber cuántas gallinas', $respaldo);
        $this->assertStringNotContainsString("\n", $respaldo);
        // Cortado en palabra: no termina a la mitad de "actualmente"
        $this->assertDoesNotMatchRegularExpression('/\bactualm?e?n?t?$/u', $respaldo);

        $corta = $this->conversacion();
        $this->mensaje($corta, MensajeAsistente::ROL_USUARIO, "¿Cuántos\nhuevos hay?");
        $this->assertSame('¿Cuántos huevos hay?', $this->servicio()->respaldoDeNombre($corta));
    }

    public function test_renombrar_marca_el_origen_manual(): void
    {
        $c = $this->conversacion();

        $this->servicio()->renombrar($c, 'Mi nombre');

        $this->assertSame('Mi nombre', $c->fresh()->nombre);
        $this->assertSame(ConversacionAsistente::NOMBRE_MANUAL, $c->fresh()->nombre_origen);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function configuracionInvalida(): array
    {
        return ['cero' => [0], 'negativo' => [-3], 'texto' => ['abc'], 'vacio' => ['']];
    }

    #[DataProvider('configuracionInvalida')]
    public function test_configuracion_invalida_cae_en_los_valores_por_defecto(mixed $invalido): void
    {
        config(['asistente.conversacion.contexto_mensajes' => $invalido]);
        $c = $this->conversacion();
        foreach (range(1, 10) as $i) {
            $this->mensaje($c, MensajeAsistente::ROL_USUARIO, "u{$i}");
            $this->mensaje($c, MensajeAsistente::ROL_MODELO, "m{$i}");
        }

        // Por defecto son 6: nunca 0 mensajes ni los 20
        $this->assertCount(6, $this->servicio()->contexto($c));
    }

    public function test_el_uuid_de_auditoria_es_determinista_con_formato_uuid_y_distinto_por_conversacion(): void
    {
        $a = $this->conversacion();
        $b = $this->conversacion();

        $this->assertTrue(Str::isUuid($this->servicio()->uuidAuditoria($a)));
        $this->assertSame($this->servicio()->uuidAuditoria($a), $this->servicio()->uuidAuditoria($a));
        $this->assertNotSame($a->uuid, $this->servicio()->uuidAuditoria($a));
        $this->assertNotSame($this->servicio()->uuidAuditoria($a), $this->servicio()->uuidAuditoria($b));
    }

    public function test_el_uuid_de_auditoria_depende_de_la_clave_de_la_aplicacion(): void
    {
        $c = $this->conversacion();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $conClaveA = $this->servicio()->uuidAuditoria($c);
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b', 32))]);
        $conClaveB = $this->servicio()->uuidAuditoria($c);

        $this->assertNotSame($conClaveA, $conClaveB);
    }

    public function test_la_auditoria_no_gana_columnas_ni_referencias_a_usuarios_ni_conversaciones(): void
    {
        $columnas = Schema::getColumnListing('mnt_funcion_log_agent');

        $this->assertNotContains('id_usuario', $columnas);
        foreach ($columnas as $columna) {
            $this->assertStringNotContainsString('conversacion_asistente', $columna);
        }
        foreach (Schema::getColumnListing('mnt_funcion_log_agent_tools') as $columna) {
            $this->assertNotSame('id_usuario', $columna);
        }
    }
}
