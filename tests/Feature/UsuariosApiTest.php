<?php

namespace Tests\Feature;

use App\Jobs\EnviarCredencialesIniciales;
use App\Mail\CredencialesIniciales;
use App\Models\Rol;
use App\Models\User;
use App\Services\Acceso\UsuarioAdminService;
use Exception;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UsuariosApiTest extends TestCase
{
    use RefreshDatabase;

    private ?string $temporal = null;

    private function idRol(string $clave = Rol::CLAVE_OPERARIO): int
    {
        return Rol::where('clave', $clave)->value('id');
    }

    private function datosNuevoUsuario(array $extra = []): array
    {
        return array_merge([
            'name' => 'Rosa Operaria',
            'email' => 'rosa@granja.test',
            'id_rol' => $this->idRol(),
        ], $extra);
    }

    /** Crea un usuario por la API con la cola simulada y devuelve el modelo. */
    private function crearPorApi(User $super): User
    {
        Queue::fake();
        $respuesta = $this->actingAs($super, 'sanctum')
            ->postJson('/api/v1/usuarios', $this->datosNuevoUsuario())
            ->assertCreated();

        return User::findOrFail($respuesta->json('data.usuario.id'));
    }

    /** Ejecuta el job a mano con SMTP "real" simulado y captura la contraseña temporal enviada. */
    private function ejecutarJob(User $usuario): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        (new EnviarCredencialesIniciales($usuario->id))->handle();
        Mail::assertSent(CredencialesIniciales::class, function (CredencialesIniciales $correo) {
            $this->temporal = $correo->passwordTemporal;

            return true;
        });
    }

    public function test_crear_usuario_lo_deja_pendiente_y_encola_el_job_con_solo_el_id(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());

        $this->assertSame('pendiente', $usuario->credenciales_estado);
        $this->assertTrue($usuario->debe_cambiar_password);
        $this->assertTrue($usuario->activo);
        $this->assertSame('operario', $usuario->rol->clave);

        Queue::assertPushed(EnviarCredencialesIniciales::class, 1);
        Queue::assertPushed(EnviarCredencialesIniciales::class, function ($job) use ($usuario) {
            // El payload serializado solo lleva el id: nunca una contraseña.
            $this->assertSame($usuario->id, $job->idUsuario);
            $payload = strtolower(serialize($job));
            $this->assertStringNotContainsString('password', $payload);
            $this->assertStringNotContainsString('clave', $payload);
            $this->assertStringNotContainsString($usuario->email, $payload);

            return true;
        });
    }

    public function test_el_job_guarda_el_hash_envia_un_solo_correo_y_marca_enviado(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());
        $hashAntes = $usuario->password;

        $this->ejecutarJob($usuario);

        Mail::assertSent(CredencialesIniciales::class, 1);
        Mail::assertSent(CredencialesIniciales::class, fn ($c) => $c->hasTo('rosa@granja.test'));

        $usuario->refresh();
        $this->assertNotSame($hashAntes, $usuario->password);
        $this->assertTrue(Hash::check($this->temporal, $usuario->password));
        $this->assertNotSame($this->temporal, $usuario->password, 'se guarda solo el hash');
        $this->assertSame(12, strlen($this->temporal));
        $this->assertSame('enviado', $usuario->credenciales_estado);
        $this->assertNull($usuario->credenciales_error);
        $this->assertTrue($usuario->debe_cambiar_password);
    }

    public function test_el_correo_esta_en_espanol_y_trae_los_datos_de_acceso(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());
        config(['app.frontend_url' => 'https://granja.test']);
        $this->ejecutarJob($usuario);

        Mail::assertSent(CredencialesIniciales::class, function (CredencialesIniciales $correo) use ($usuario) {
            $html = $correo->render();
            $this->assertStringContainsString($usuario->email, $html);
            $this->assertStringContainsString($correo->passwordTemporal, $html);
            $this->assertStringContainsString('https://granja.test', $html);
            $this->assertStringContainsString('contraseña', $html);

            return true;
        });
    }

    public function test_la_respuesta_no_contiene_la_contrasena_temporal(): void
    {
        Queue::fake();
        $respuesta = $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/usuarios', $this->datosNuevoUsuario())
            ->assertCreated()
            ->assertJsonPath('data.credenciales_estado', 'pendiente');

        $usuario = $respuesta->json('data.usuario');
        $this->assertArrayNotHasKey('password', $usuario);
        $this->assertArrayNotHasKey('remember_token', $usuario);

        // Ni la clave aleatoria guardada (hash) ni ninguna temporal viajan en la respuesta.
        $hash = User::findOrFail($usuario['id'])->password;
        $this->assertStringNotContainsString($hash, json_encode($respuesta->json()));
        $this->assertTrue($usuario['debe_cambiar_password']);
    }

    public function test_con_mailer_log_el_job_no_envia_y_marca_fallido(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());
        $hashAntes = $usuario->password;

        config(['mail.default' => 'log']);
        Mail::fake();
        (new EnviarCredencialesIniciales($usuario->id))->handle();

        Mail::assertNothingSent();
        $usuario->refresh();
        $this->assertSame('fallido', $usuario->credenciales_estado);
        $this->assertSame('El servidor de correo no está configurado.', $usuario->credenciales_error);
        $this->assertSame($hashAntes, $usuario->password, 'sin envío no se genera ninguna temporal');
    }

    public function test_si_el_transporte_falla_queda_fallido_sin_contrasena_en_el_error(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());

        (new EnviarCredencialesIniciales($usuario->id))->failed(new Exception('SMTP caido, clave=Secreta123'));

        $usuario->refresh();
        $this->assertSame('fallido', $usuario->credenciales_estado);
        $this->assertStringNotContainsString('Secreta123', (string) $usuario->credenciales_error);
        $this->assertNotEmpty($usuario->credenciales_error);
    }

    public function test_un_fallo_del_envio_se_propaga_para_que_el_worker_reintente(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('to')->andThrow(new Exception('SMTP caido'));

        $this->expectException(Exception::class);
        (new EnviarCredencialesIniciales($usuario->id))->handle();
    }

    public function test_con_mailer_failover_que_incluye_log_el_job_no_envia(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());
        $hashAntes = $usuario->password;

        // `failover` cae a `log` si el SMTP falla: la clave en claro acabaría en laravel.log.
        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']],
        ]);
        Mail::fake();
        (new EnviarCredencialesIniciales($usuario->id))->handle();

        Mail::assertNothingSent();
        $usuario->refresh();
        $this->assertSame('fallido', $usuario->credenciales_estado);
        $this->assertSame($hashAntes, $usuario->password);
    }

    public function test_con_mailer_failover_solo_con_smtp_si_envia(): void
    {
        $usuario = $this->crearPorApi(User::factory()->create());

        config([
            'mail.default' => 'failover',
            'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'ses']],
        ]);
        Mail::fake();
        (new EnviarCredencialesIniciales($usuario->id))->handle();

        Mail::assertSent(CredencialesIniciales::class, 1);
    }

    public function test_el_job_ignora_un_usuario_que_ya_no_existe(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();

        (new EnviarCredencialesIniciales(99999))->handle();

        Mail::assertNothingSent();
    }

    public function test_el_job_no_envia_credenciales_a_un_usuario_desactivado(): void
    {
        $usuario = User::factory()->inactivo()->create(['credenciales_estado' => 'pendiente']);
        config(['mail.default' => 'smtp']);
        Mail::fake();

        (new EnviarCredencialesIniciales($usuario->id))->handle();

        Mail::assertNothingSent();
    }

    public function test_reenviar_credenciales_vuelve_a_pendiente_y_la_anterior_deja_de_servir(): void
    {
        $super = User::factory()->create();
        $usuario = $this->crearPorApi($super);
        $this->ejecutarJob($usuario);
        $primera = $this->temporal;
        $usuario->createToken('viejo');

        Queue::fake();
        $this->actingAs($super, 'sanctum')
            ->postJson("/api/v1/usuarios/{$usuario->id}/reenviar-credenciales")
            ->assertOk()
            ->assertJsonPath('data.credenciales_estado', 'pendiente');

        $usuario->refresh();
        $this->assertSame('pendiente', $usuario->credenciales_estado);
        $this->assertTrue($usuario->debe_cambiar_password);
        $this->assertFalse(Hash::check($primera, $usuario->password), 'la temporal anterior ya no sirve');
        $this->assertSame(0, $usuario->tokens()->count());
        Queue::assertPushed(EnviarCredencialesIniciales::class, 1);

        $this->ejecutarJob($usuario);
        $this->assertNotSame($primera, $this->temporal);
        $this->assertTrue(Hash::check($this->temporal, $usuario->fresh()->password));
    }

    public function test_correo_duplicado_da_422(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'rosa@granja.test']);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/usuarios', $this->datosNuevoUsuario())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);

        Queue::assertNothingPushed();
    }

    public function test_la_creacion_valida_nombre_correo_y_rol(): void
    {
        Queue::fake();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/usuarios', ['email' => 'no-es-correo', 'id_rol' => 9999])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'id_rol']);
    }

    public function test_el_listado_trae_rol_y_estado_de_credenciales_sin_secretos(): void
    {
        $super = User::factory()->create();
        $this->crearPorApi($super);

        $respuesta = $this->actingAs($super, 'sanctum')->getJson('/api/v1/usuarios')->assertOk();

        $fila = collect($respuesta->json('data'))->firstWhere('email', 'rosa@granja.test');
        $this->assertSame('operario', $fila['rol']['clave']);
        $this->assertTrue($fila['activo']);
        $this->assertSame('pendiente', $fila['credenciales_estado']);
        $this->assertArrayNotHasKey('password', $fila);
        $this->assertArrayNotHasKey('remember_token', $fila);
    }

    public function test_operario_no_puede_crear_usuarios(): void
    {
        Queue::fake();
        $operario = User::factory()->operario()->create();

        $this->actingAs($operario, 'sanctum')->getJson('/api/v1/usuarios')->assertForbidden();
        $this->actingAs($operario, 'sanctum')
            ->postJson('/api/v1/usuarios', $this->datosNuevoUsuario())
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_desactivar_revoca_tokens_y_bloquea_el_login(): void
    {
        $super = User::factory()->create();
        $usuario = $this->crearPorApi($super);
        $usuario->forceFill(['password' => 'ClaveConocida1'])->save();
        $usuario->createToken('sesion');

        $this->actingAs($super, 'sanctum')
            ->patchJson("/api/v1/usuarios/{$usuario->id}", ['activo' => false])
            ->assertOk()
            ->assertJsonPath('data.activo', false);

        $this->assertSame(0, $usuario->tokens()->count());
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email, 'password' => 'ClaveConocida1'])
            ->assertStatus(422);
    }

    public function test_cambiar_rol_revoca_tokens(): void
    {
        $super = User::factory()->create();
        $usuario = $this->crearPorApi($super);
        $usuario->createToken('sesion');

        $this->actingAs($super, 'sanctum')
            ->patchJson("/api/v1/usuarios/{$usuario->id}", ['id_rol' => $this->idRol(Rol::CLAVE_SUPER_ADMIN)])
            ->assertOk()
            ->assertJsonPath('data.rol.clave', 'super_admin');

        $this->assertSame(0, $usuario->tokens()->count());
    }

    public function test_reactivar_un_usuario_no_revoca_nada_y_le_devuelve_el_acceso(): void
    {
        $super = User::factory()->create();
        $usuario = User::factory()->operario()->inactivo()->create();

        $this->actingAs($super, 'sanctum')
            ->patchJson("/api/v1/usuarios/{$usuario->id}", ['activo' => true])
            ->assertOk()
            ->assertJsonPath('data.activo', true);

        $this->assertTrue($usuario->fresh()->activo);
    }

    public function test_no_se_puede_desactivar_ni_degradar_al_unico_super_admin(): void
    {
        // Por la API solo un superAdmin llega aquí y nadie se edita a sí mismo, así que el «último
        // superAdmin» se prueba contra el servicio, con un actor distinto (defensa en profundidad).
        $unico = User::factory()->create();
        $actor = User::factory()->operario()->create();
        $servicio = app(UsuarioAdminService::class);
        $operario = Rol::where('clave', Rol::CLAVE_OPERARIO)->firstOrFail();

        try {
            $servicio->cambiarEstado($unico, false, $actor);
            $this->fail('Debió negarse a desactivar al único superAdmin.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activo', $e->errors());
        }

        try {
            $servicio->cambiarRol($unico, $operario, $actor);
            $this->fail('Debió negarse a degradar al único superAdmin.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('id_rol', $e->errors());
        }

        $unico->refresh();
        $this->assertTrue($unico->activo);
        $this->assertTrue($unico->esSuperAdmin());
    }

    public function test_la_regla_del_ultimo_super_admin_se_evalua_dentro_de_una_transaccion_con_filas_bloqueadas(): void
    {
        // Dos superAdmin que se desactivan a la vez verían cada uno «count = 2». La defensa es contar
        // y escribir dentro de la misma transacción, bloqueando las filas de los superAdmin activos
        // (PostgreSQL serializa las peticiones concurrentes). SQLite ignora el bloqueo, así que se
        // comprueba el orden de las operaciones: BEGIN → SELECT ... FOR UPDATE → UPDATE → COMMIT.
        $actor = User::factory()->create();
        $objetivo = User::factory()->create();
        $eventos = [];

        DB::listen(function ($consulta) use (&$eventos) {
            $sql = strtolower($consulta->sql);
            if (str_contains($sql, 'mnt_usuarios') && str_starts_with($sql, 'update')) {
                $eventos[] = 'update';
            }
        });
        Event::listen(TransactionBeginning::class, function () use (&$eventos) {
            $eventos[] = 'begin';
        });
        Event::listen(TransactionCommitted::class, function () use (&$eventos) {
            $eventos[] = 'commit';
        });

        app(UsuarioAdminService::class)->cambiarEstado($objetivo, false, $actor);

        $this->assertSame(['begin', 'update', 'commit'], array_values(array_filter($eventos, fn ($e) => $e !== null)));
        $this->assertFalse($objetivo->fresh()->activo);
    }

    public function test_un_superadmin_desactivado_no_cuenta_como_ultimo_super_admin_activo(): void
    {
        $activo = User::factory()->create();
        User::factory()->inactivo()->create();
        $actor = User::factory()->operario()->create();

        $this->expectException(ValidationException::class);
        app(UsuarioAdminService::class)->cambiarEstado($activo, false, $actor);
    }

    public function test_no_se_reenvian_credenciales_a_uno_mismo_ni_a_un_desactivado(): void
    {
        Queue::fake();
        $super = User::factory()->create();
        $inactivo = User::factory()->operario()->inactivo()->create();

        $this->actingAs($super, 'sanctum');
        $this->postJson("/api/v1/usuarios/{$super->id}/reenviar-credenciales")->assertStatus(422);
        $this->postJson("/api/v1/usuarios/{$inactivo->id}/reenviar-credenciales")->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertTrue(Hash::check('password', $super->fresh()->password), 'su clave no se toca');
    }

    public function test_con_dos_super_admin_se_puede_degradar_a_uno(): void
    {
        $actor = User::factory()->create();
        $otro = User::factory()->create();

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/usuarios/{$otro->id}", ['id_rol' => $this->idRol()])
            ->assertOk();

        $this->assertSame('operario', $otro->fresh()->rol->clave);
    }

    public function test_nadie_se_desactiva_ni_se_cambia_el_rol_a_si_mismo(): void
    {
        $actor = User::factory()->create();
        User::factory()->create(); // segundo superAdmin: la negativa no es por ser el último

        $this->actingAs($actor, 'sanctum');
        $this->patchJson("/api/v1/usuarios/{$actor->id}", ['activo' => false])->assertStatus(422);
        $this->patchJson("/api/v1/usuarios/{$actor->id}", ['id_rol' => $this->idRol()])->assertStatus(422);

        $actor->refresh();
        $this->assertTrue($actor->activo);
        $this->assertTrue($actor->esSuperAdmin());
    }

    public function test_el_patch_exige_al_menos_un_campo(): void
    {
        $super = User::factory()->create();
        $otro = User::factory()->operario()->create();

        $this->actingAs($super, 'sanctum')
            ->patchJson("/api/v1/usuarios/{$otro->id}", [])
            ->assertStatus(422);
    }
}
