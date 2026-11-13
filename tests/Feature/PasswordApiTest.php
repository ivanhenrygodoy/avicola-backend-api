<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordApiTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORAL = 'TempAbc123456';

    private function usuarioConTemporal(): User
    {
        return User::factory()->operario()->create([
            'password' => self::TEMPORAL,
            'debe_cambiar_password' => true,
        ]);
    }

    /** Inicia sesión por la API y devuelve el token Bearer. */
    private function token(User $usuario, string $password): string
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $usuario->email,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    private function datos(array $extra = []): array
    {
        return array_merge([
            'password_actual' => self::TEMPORAL,
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ], $extra);
    }

    public function test_cambiar_password_valida_la_actual(): void
    {
        $usuario = $this->usuarioConTemporal();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/auth/password', $this->datos(['password_actual' => 'incorrecta']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password_actual']);

        $usuario->refresh();
        $this->assertTrue(Hash::check(self::TEMPORAL, $usuario->password));
        $this->assertTrue($usuario->debe_cambiar_password);
    }

    public function test_cambiar_password_actualiza_hash_apaga_la_bandera_y_revoca_todos_los_tokens(): void
    {
        $usuario = $this->usuarioConTemporal();
        $this->token($usuario, self::TEMPORAL);
        $tokenActual = $this->token($usuario, self::TEMPORAL);
        $this->assertSame(2, $usuario->tokens()->count());

        $this->withHeader('Authorization', "Bearer $tokenActual")
            ->postJson('/api/v1/auth/password', $this->datos())
            ->assertOk()
            ->assertJsonPath('success', true);

        $usuario->refresh();
        $this->assertTrue(Hash::check('NuevaClave2026', $usuario->password));
        $this->assertFalse($usuario->debe_cambiar_password);
        $this->assertSame(0, $usuario->tokens()->count(), 'incluido el token actual: debe volver a iniciar sesión');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer $tokenActual")
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_la_temporal_anterior_deja_de_servir_tras_cambiar(): void
    {
        $usuario = $this->usuarioConTemporal();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/auth/password', $this->datos())
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email, 'password' => self::TEMPORAL])
            ->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email, 'password' => 'NuevaClave2026'])
            ->assertOk();
    }

    public function test_mantener_apaga_la_bandera_sin_tocar_la_password(): void
    {
        $usuario = $this->usuarioConTemporal();
        $token = $this->token($usuario, self::TEMPORAL);
        $hashAntes = $usuario->fresh()->password;

        $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/password/mantener')
            ->assertOk()
            ->assertJsonPath('success', true);

        $usuario->refresh();
        $this->assertFalse($usuario->debe_cambiar_password);
        $this->assertSame($hashAntes, $usuario->password);
        $this->assertSame(1, $usuario->tokens()->count(), 'mantener no cierra la sesión');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => $usuario->email, 'password' => self::TEMPORAL])
            ->assertOk()
            ->assertJsonPath('data.user.debe_cambiar_password', false);
    }

    public function test_confirmacion_distinta_da_422(): void
    {
        $usuario = $this->usuarioConTemporal();

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/auth/password', $this->datos(['password_confirmation' => 'otra-distinta']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check(self::TEMPORAL, $usuario->fresh()->password));
    }

    public function test_la_nueva_password_exige_minimo_8_caracteres(): void
    {
        $this->actingAs($this->usuarioConTemporal(), 'sanctum')
            ->postJson('/api/v1/auth/password', $this->datos(['password' => 'corta1', 'password_confirmation' => 'corta1']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_la_nueva_password_no_puede_ser_igual_a_la_actual(): void
    {
        $this->actingAs($this->usuarioConTemporal(), 'sanctum')
            ->postJson('/api/v1/auth/password', $this->datos([
                'password' => self::TEMPORAL,
                'password_confirmation' => self::TEMPORAL,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_un_operario_sin_modulo_alguno_puede_cambiar_su_password(): void
    {
        $usuario = User::factory()->sinRol()->create(['password' => self::TEMPORAL]);

        $this->actingAs($usuario, 'sanctum')
            ->postJson('/api/v1/auth/password', $this->datos())
            ->assertOk();
    }

    public function test_sin_autenticar_da_401(): void
    {
        $this->postJson('/api/v1/auth/password', $this->datos())->assertUnauthorized();
        $this->postJson('/api/v1/auth/password/mantener')->assertUnauthorized();
    }
}
