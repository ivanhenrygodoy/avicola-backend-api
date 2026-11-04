<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'name' => 'Administrador Granja',
            'email' => 'admin@granja.local',
            'password' => Hash::make('password123'),
        ]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@granja.local',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Inicio de sesión exitoso.',
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'email'],
                ],
            ]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@granja.local',
            'password' => 'wrong_password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_access_me_endpoint_with_valid_token(): void
    {
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@granja.local',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        $response = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'email' => 'admin@granja.local',
                ],
            ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(401);
    }

    public function test_user_can_logout_and_token_is_revoked(): void
    {
        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@granja.local',
            'password' => 'password123',
        ]);

        $token = $loginResponse->json('data.token');

        // Logout
        $logoutResponse = $this->withHeader('Authorization', "Bearer $token")
            ->postJson('/api/v1/auth/logout');

        $logoutResponse->assertStatus(200)
            ->assertJson(['success' => true]);

        // Olvidamos el estado en memoria de la guardia para simular una nueva petición HTTP independiente
        $this->app['auth']->forgetGuards();

        // Verificamos que el token ya no tenga acceso en BD
        $afterLogoutResponse = $this->withHeader('Authorization', "Bearer $token")
            ->getJson('/api/v1/auth/me');

        $afterLogoutResponse->assertStatus(401);
    }
}
