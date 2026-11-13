<?php

namespace Tests\Feature;

use App\Models\Modulo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthPermisosTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user, string $password = 'password'): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
    }

    /** @param array<int, array<string, mixed>> $arbol */
    private function claves(array $arbol): array
    {
        $claves = [];
        foreach ($arbol as $nodo) {
            $claves[] = $nodo['clave'];
            $claves = array_merge($claves, $this->claves($nodo['hijos']));
        }

        return $claves;
    }

    public function test_login_devuelve_rol_arbol_y_permisos(): void
    {
        $operario = User::factory()->operario()->create();

        $respuesta = $this->login($operario)->assertOk();

        $respuesta->assertJsonPath('data.user.rol.clave', 'operario')
            ->assertJsonPath('data.user.debe_cambiar_password', false)
            ->assertJsonStructure(['data' => ['user' => [
                'id', 'name', 'email', 'rol' => ['clave', 'nombre'], 'modulos', 'permisos', 'debe_cambiar_password',
            ]]]);

        $permisos = $respuesta->json('data.user.permisos');
        $this->assertContains('asistente', $permisos);
        $this->assertNotContains('benchmark', $permisos);

        // El grupo «Asistente IA» sin hijos visibles no aparece en el árbol.
        $claves = $this->claves($respuesta->json('data.user.modulos'));
        $this->assertContains('dashboard', $claves);
        $this->assertNotContains('asistente', $claves);
        $this->assertNotContains('benchmark', $claves);
    }

    public function test_el_grupo_asistente_ia_aparece_para_super_admin_con_benchmark_dentro(): void
    {
        $respuesta = $this->login(User::factory()->create())->assertOk();

        $arbol = collect($respuesta->json('data.user.modulos'));
        $grupo = $arbol->firstWhere('clave', 'asistente');

        $this->assertNotNull($grupo);
        $this->assertSame('Asistente IA', $grupo['nombre']);
        $this->assertNull($grupo['ruta']);
        $this->assertSame(['benchmark'], array_column($grupo['hijos'], 'clave'));
        $this->assertSame('/benchmark', $grupo['hijos'][0]['ruta']);

        // El árbol viene ordenado como el menú actual.
        $this->assertSame(
            ['dashboard', 'instalaciones', 'aves', 'produccion', 'gastos', 'asistente', 'usuarios', 'permisos'],
            $arbol->pluck('clave')->all()
        );
        $this->assertTrue($arbol->firstWhere('clave', 'dashboard')['exacto']);
    }

    public function test_el_operario_con_benchmark_otorgado_ve_el_grupo_con_su_hijo(): void
    {
        $operario = User::factory()->operario()->create();
        $operario->rol->modulos()->attach(Modulo::where('clave', 'benchmark')->value('id'));

        $arbol = collect($this->login($operario)->json('data.user.modulos'));

        $this->assertSame(['benchmark'], array_column($arbol->firstWhere('clave', 'asistente')['hijos'], 'clave'));
    }

    public function test_me_devuelve_los_mismos_datos(): void
    {
        $operario = User::factory()->operario()->create();
        $login = $this->login($operario)->json('data.user');

        $me = $this->actingAs($operario, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

        $this->assertSame($login['modulos'], $me->json('data.modulos'));
        $this->assertSame($login['permisos'], $me->json('data.permisos'));
        $this->assertSame('operario', $me->json('data.rol.clave'));
        $this->assertSame($operario->email, $me->json('data.email'));
        $this->assertFalse($me->json('data.debe_cambiar_password'));
    }

    public function test_usuario_inactivo_no_puede_iniciar_sesion(): void
    {
        $user = User::factory()->inactivo()->create();

        $this->login($user)
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Tu cuenta está desactivada. Contacta al administrador.');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_usuario_inactivo_con_clave_incorrecta_no_revela_que_esta_desactivado(): void
    {
        $user = User::factory()->inactivo()->create();

        $this->login($user, 'incorrecta')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Las credenciales proporcionadas no son correctas.');
    }

    public function test_login_devuelve_debe_cambiar_password(): void
    {
        $user = User::factory()->operario()->create(['debe_cambiar_password' => true]);

        $this->login($user)->assertOk()->assertJsonPath('data.user.debe_cambiar_password', true);
    }

    public function test_un_usuario_sin_rol_inicia_sesion_pero_no_tiene_modulos(): void
    {
        $respuesta = $this->login(User::factory()->sinRol()->create())->assertOk();

        $this->assertNull($respuesta->json('data.user.rol'));
        $this->assertSame([], $respuesta->json('data.user.modulos'));
        $this->assertSame([], $respuesta->json('data.user.permisos'));
    }
}
