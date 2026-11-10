<?php

namespace Tests\Feature;

use App\Models\Ave;
use App\Models\CtlCategoriaGasto;
use App\Models\CtlEspecie;
use App\Models\CtlSexo;
use App\Models\CtlTipoAlimento;
use App\Models\Division;
use App\Models\Gasto;
use App\Models\RegistroAlimentacion;
use App\Models\RegistroHuevo;
use App\Models\User;
use App\Services\Asistente\AsistenteOrquestadorService;
use App\Services\Asistente\AsistenteToolRegistry;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use App\Services\Asistente\GeminiApiClient;
use App\Services\Asistente\ModuloNavigationResolver;
use App\Services\Asistente\PromptComposer;
use App\Services\Asistente\Tools\ConsultarGastosPeriodoTool;
use App\Services\Asistente\Tools\ConsultarInventarioAvesTool;
use App\Services\Asistente\Tools\ConsultarProduccionHuevosTool;
use App\Services\Asistente\Tools\ConsultarRacionesAlimentoTool;
use App\Services\Asistente\Tools\ConsultarUbicacionAveTool;
use Database\Seeders\CatalogoAvesSeeder;
use Database\Seeders\InstalacionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AsistenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_composer_genera_system_prompt_con_las_cuatro_capas(): void
    {
        $prompt = PromptComposer::componer();

        // 1. Capa Invariante / Identidad
        $this->assertStringContainsString('Asistente Inteligente', $prompt);
        $this->assertStringContainsString('fuente de verdad', $prompt);

        // 2. Capa de Seguridad
        $this->assertStringContainsString('instrucciones del sistema', $prompt);
        $this->assertStringContainsString('inyección', $prompt);

        // 3. Capa de Estilo
        $this->assertStringContainsString('1 a 3 oraciones', $prompt);
        $this->assertStringContainsString('DD-MM-YYYY', $prompt);

        // 4. Capa de Dominio
        $this->assertStringContainsString('casitas', strtolower($prompt));
        $this->assertStringContainsString('divisiones', strtolower($prompt));
        $this->assertStringContainsString('aprovechables', strtolower($prompt));
    }

    public function test_registry_genera_declaraciones_de_tools_en_formato_gemini(): void
    {
        $toolMock = new class implements AsistenteToolInterface
        {
            public function nombre(): string
            {
                return 'herramienta_prueba';
            }

            public function descripcion(): string
            {
                return 'Descripción de prueba.';
            }

            public function tablasConsultadas(): array
            {
                return [];
            }

            public function esquema(): array
            {
                return [
                    'type' => 'OBJECT',
                    'properties' => [
                        'parametro1' => [
                            'type' => 'STRING',
                            'description' => 'Un parámetro de prueba.',
                        ],
                    ],
                    'required' => ['parametro1'],
                ];
            }

            public function ejecutar(array $argumentos, array $contextoFijo = []): array
            {
                return ['resultado' => 'ok', 'contexto' => $contextoFijo];
            }
        };

        $registry = new AsistenteToolRegistry([$toolMock]);
        $declaraciones = $registry->obtenerDeclaracionesTools();

        $this->assertCount(1, $declaraciones);
        $this->assertSame('herramienta_prueba', $declaraciones[0]['name']);
        $this->assertSame('Descripción de prueba.', $declaraciones[0]['description']);
        $this->assertArrayHasKey('parameters', $declaraciones[0]);
        $this->assertSame('OBJECT', $declaraciones[0]['parameters']['type']);
    }

    public function test_registry_ejecuta_herramienta_inyectando_contexto_fijo(): void
    {
        $toolMock = new class implements AsistenteToolInterface
        {
            public function nombre(): string
            {
                return 'consultar_prueba';
            }

            public function descripcion(): string
            {
                return 'Prueba de ejecución.';
            }

            public function tablasConsultadas(): array
            {
                return [];
            }

            public function esquema(): array
            {
                return ['type' => 'OBJECT', 'properties' => []];
            }

            public function ejecutar(array $argumentos, array $contextoFijo = []): array
            {
                return [
                    'argumentos' => $argumentos,
                    'id_usuario' => $contextoFijo['id_usuario'] ?? null,
                ];
            }
        };

        $registry = new AsistenteToolRegistry([$toolMock]);
        $resultado = $registry->ejecutar('consultar_prueba', ['filtro' => 'activo'], ['id_usuario' => 42]);

        $this->assertSame(['filtro' => 'activo'], $resultado['argumentos']);
        $this->assertSame(42, $resultado['id_usuario']);
    }

    public function test_registry_lanza_excepcion_para_herramienta_no_registrada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('La herramienta [inexistente] no está registrada.');

        $registry = new AsistenteToolRegistry([]);
        $registry->ejecutar('inexistente', []);
    }

    public function test_tool_consultar_inventario_aves(): void
    {
        $this->seed(CatalogoAvesSeeder::class);
        $this->seed(InstalacionesSeeder::class);

        $division = Division::first();
        $especieGallina = CtlEspecie::where('nombre', 'Gallina')->first();
        $especiePato = CtlEspecie::where('nombre', 'Pato')->first();
        $sexoHembra = CtlSexo::where('nombre', 'Hembra')->first();

        Ave::create([
            'id_division' => $division->id,
            'id_especie' => $especieGallina->id,
            'id_sexo' => $sexoHembra->id,
            'codigo' => 'AVE-TEST-01',
            'nombre' => 'Gallina Uno',
            'estado' => 'activa',
            'peso_actual_kg' => 2.1,
        ]);

        Ave::create([
            'id_division' => $division->id,
            'id_especie' => $especieGallina->id,
            'id_sexo' => $sexoHembra->id,
            'codigo' => 'AVE-TEST-02',
            'nombre' => 'Gallina Dos',
            'estado' => 'activa',
            'peso_actual_kg' => 2.3,
        ]);

        Ave::create([
            'id_division' => $division->id,
            'id_especie' => $especiePato->id,
            'id_sexo' => $sexoHembra->id,
            'codigo' => 'AVE-TEST-03',
            'nombre' => 'Pato Uno',
            'estado' => 'activa',
            'peso_actual_kg' => 3.0,
        ]);

        Ave::create([
            'id_division' => null,
            'id_especie' => $especieGallina->id,
            'id_sexo' => $sexoHembra->id,
            'codigo' => 'AVE-TEST-04',
            'nombre' => 'Gallina Salida',
            'estado' => 'salida',
        ]);

        $tool = new ConsultarInventarioAvesTool;
        $resumen = $tool->ejecutar([]);

        $this->assertSame(3, $resumen['total_aves']);
        $this->assertSame(2, $resumen['por_especie']['Gallina'] ?? null);
        $this->assertSame(1, $resumen['por_especie']['Pato'] ?? null);
        $this->assertCount(3, $resumen['ejemplos']);

        $resumenGallinas = $tool->ejecutar(['especie' => 'Gallina']);
        $this->assertSame(2, $resumenGallinas['total_aves']);
    }

    public function test_tool_consultar_produccion_huevos(): void
    {
        $this->seed(InstalacionesSeeder::class);
        $division = Division::first();
        $usuario = User::factory()->create();

        RegistroHuevo::create([
            'id_division' => $division->id,
            'id_usuario' => $usuario->id,
            'fecha' => now()->format('Y-m-d'),
            'cantidad_recolectada' => 10,
            'cantidad_rotos' => 2,
            'cantidad_aprovechables' => 8,
        ]);

        RegistroHuevo::create([
            'id_division' => $division->id,
            'id_usuario' => $usuario->id,
            'fecha' => now()->subDay()->format('Y-m-d'),
            'cantidad_recolectada' => 20,
            'cantidad_rotos' => 1,
            'cantidad_aprovechables' => 19,
        ]);

        $tool = new ConsultarProduccionHuevosTool;
        $resultado = $tool->ejecutar(['dias' => 7]);

        $this->assertSame(30, $resultado['total_recolectados']);
        $this->assertSame(3, $resultado['total_rotos']);
        $this->assertSame(27, $resultado['total_aprovechables']);
        $this->assertSame(10.0, $resultado['porcentaje_rotura']);
        $this->assertNotEmpty($resultado['resumen_diario_reciente']);
    }

    public function test_tool_consultar_gastos_periodo(): void
    {
        $this->seed(InstalacionesSeeder::class);
        $division = Division::first();
        $usuario = User::factory()->create();

        $catAlimento = CtlCategoriaGasto::create(['nombre' => 'Alimento y Forraje', 'activo' => true]);
        $catMedicina = CtlCategoriaGasto::create(['nombre' => 'Medicina y Sanidad', 'activo' => true]);

        Gasto::create([
            'id_categoria_gasto' => $catAlimento->id,
            'id_division' => $division->id,
            'id_usuario' => $usuario->id,
            'fecha' => now()->format('Y-m-d'),
            'monto' => 50.00,
            'concepto' => 'Saco de maíz',
            'proveedor' => 'Agroveterinaria',
        ]);

        Gasto::create([
            'id_categoria_gasto' => $catMedicina->id,
            'id_division' => $division->id,
            'id_usuario' => $usuario->id,
            'fecha' => now()->format('Y-m-d'),
            'monto' => 30.00,
            'concepto' => 'Vitaminas',
            'proveedor' => 'Farmacia',
        ]);

        $tool = new ConsultarGastosPeriodoTool;
        $resultado = $tool->ejecutar(['mes' => (int) now()->format('m'), 'anio' => (int) now()->format('Y')]);

        $this->assertSame(80.0, $resultado['total_gastado']);
        $this->assertCount(2, $resultado['por_categoria']);
        $this->assertCount(2, $resultado['ultimos_gastos']);
    }

    public function test_tool_consultar_ubicacion_ave(): void
    {
        $this->seed(CatalogoAvesSeeder::class);
        $this->seed(InstalacionesSeeder::class);

        $division = Division::with('casita')->first();
        $especie = CtlEspecie::first();
        $sexo = CtlSexo::first();

        Ave::create([
            'id_division' => $division->id,
            'id_especie' => $especie->id,
            'id_sexo' => $sexo->id,
            'codigo' => 'AVE-999',
            'nombre' => 'Blanquita',
            'estado' => 'activa',
            'peso_actual_kg' => 2.45,
        ]);

        $tool = new ConsultarUbicacionAveTool;

        // Búsqueda por nombre (case-insensitive)
        $resultado = $tool->ejecutar(['criterio' => 'blanquita']);
        $this->assertTrue($resultado['encontrado']);
        $this->assertSame('AVE-999', $resultado['ave']['codigo']);
        $this->assertSame('Blanquita', $resultado['ave']['nombre']);
        $this->assertSame($division->casita->nombre, $resultado['ave']['ubicacion']['casita']);
        $this->assertSame($division->nombre, $resultado['ave']['ubicacion']['division']);

        // Búsqueda de inexistente
        $noExiste = $tool->ejecutar(['criterio' => 'Fantasma']);
        $this->assertFalse($noExiste['encontrado']);
    }

    public function test_tool_consultar_raciones_alimento(): void
    {
        $this->seed(InstalacionesSeeder::class);
        $division = Division::first();
        $usuario = User::factory()->create();

        $tipoAlimento = CtlTipoAlimento::create([
            'nombre' => 'Concentrado Postura',
            'descripcion' => 'Alimento balanceado',
            'activo' => true,
        ]);

        RegistroAlimentacion::create([
            'id_division' => $division->id,
            'id_tipo_alimento' => $tipoAlimento->id,
            'id_usuario' => $usuario->id,
            'fecha' => now()->format('Y-m-d'),
            'hora' => '08:00',
            'cantidad' => 10.0,
            'unidad_medida' => 'kg',
            'costo_total' => 12.50,
        ]);

        RegistroAlimentacion::create([
            'id_division' => $division->id,
            'id_tipo_alimento' => $tipoAlimento->id,
            'id_usuario' => $usuario->id,
            'fecha' => now()->subDay()->format('Y-m-d'),
            'hora' => '08:00',
            'cantidad' => 5.5,
            'unidad_medida' => 'kg',
            'costo_total' => 6.88,
        ]);

        $tool = new ConsultarRacionesAlimentoTool;
        $resultado = $tool->ejecutar(['dias' => 7]);

        $this->assertSame(15.5, $resultado['total_kilos']);
        $this->assertSame(19.38, $resultado['total_costo']);
        $this->assertNotEmpty($resultado['ultimos_suministros']);
    }

    public function test_cliente_gemini_lanza_excepcion_si_no_hay_api_key(): void
    {
        config(['gemini.api_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('La clave de API de Gemini no está configurada.');

        $client = new GeminiApiClient;
        $client->generarContenido([['role' => 'user', 'parts' => [['text' => 'Hola']]]]);
    }

    public function test_cliente_gemini_maneja_error_de_red_o_servicio_indisponible(): void
    {
        config(['gemini.api_key' => 'fake-test-key']);

        Http::fake([
            '*' => Http::response(['error' => ['message' => 'Quota exceeded']], 429),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error en el servicio de IA de Gemini (429)');

        $client = new GeminiApiClient;
        $client->generarContenido([['role' => 'user', 'parts' => [['text' => 'Hola']]]]);
    }

    public function test_orquestador_procesa_conversacion_con_respuesta_directa_sin_tools(): void
    {
        config(['gemini.api_key' => 'fake-test-key']);

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'role' => 'model',
                            'parts' => [
                                ['text' => '¡Hola! Estoy listo para asistirte en la granja avícola.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $client = new GeminiApiClient;
        $registry = new AsistenteToolRegistry([]);
        $orquestador = new AsistenteOrquestadorService($client, $registry);

        $resultado = $orquestador->procesarConversacion('Hola, ¿qué tal?');

        $this->assertSame('¡Hola! Estoy listo para asistirte en la granja avícola.', $resultado['respuesta']);
        $this->assertEmpty($resultado['herramientas_utilizadas']);
    }

    public function test_orquestador_ejecuta_ciclo_function_calling_y_retorna_respuesta_sintetizada(): void
    {
        config(['gemini.api_key' => 'fake-test-key']);

        $this->seed(CatalogoAvesSeeder::class);
        $this->seed(InstalacionesSeeder::class);

        $especie = CtlEspecie::where('nombre', 'Gallina')->first();
        $sexo = CtlSexo::where('nombre', 'Hembra')->first();
        $division = Division::first();

        Ave::create([
            'id_division' => $division->id,
            'id_especie' => $especie->id,
            'id_sexo' => $sexo->id,
            'codigo' => 'AVE-TEST-G1',
            'estado' => 'activa',
        ]);

        Ave::create([
            'id_division' => $division->id,
            'id_especie' => $especie->id,
            'id_sexo' => $sexo->id,
            'codigo' => 'AVE-TEST-G2',
            'estado' => 'activa',
        ]);

        // Secuencia HTTP:
        // Petición 1: Gemini responde pidiendo llamar a consultar_inventario_aves
        // Petición 2: Gemini recibe el resultado de la tool y devuelve la redacción final
        Http::fake([
            '*' => Http::sequence()
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'role' => 'model',
                                'parts' => [
                                    [
                                        'functionCall' => [
                                            'name' => 'consultar_inventario_aves',
                                            'args' => ['especie' => 'Gallina'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'role' => 'model',
                                'parts' => [
                                    ['text' => 'Actualmente la granja cuenta con 2 gallinas activas.'],
                                ],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        $client = new GeminiApiClient;
        $registry = new AsistenteToolRegistry([new ConsultarInventarioAvesTool]);
        $orquestador = new AsistenteOrquestadorService($client, $registry);

        $resultado = $orquestador->procesarConversacion('¿Cuántas gallinas tenemos registradas?');

        $this->assertSame('Actualmente la granja cuenta con 2 gallinas activas.', $resultado['respuesta']);
        $this->assertSame(['consultar_inventario_aves'], $resultado['herramientas_utilizadas']);
    }

    public function test_orquestador_previene_bucles_infinitos_deteniendose_en_maximo_iteraciones(): void
    {
        config(['gemini.api_key' => 'fake-test-key']);

        // Gemini pide llamar tools infinitamente
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'role' => 'model',
                            'parts' => [
                                [
                                    'functionCall' => [
                                        'name' => 'tool_infinita',
                                        'args' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $toolMock = new class implements AsistenteToolInterface
        {
            public function nombre(): string
            {
                return 'tool_infinita';
            }

            public function descripcion(): string
            {
                return 'Tool infinita';
            }

            public function tablasConsultadas(): array
            {
                return [];
            }

            public function esquema(): array
            {
                return ['type' => 'OBJECT', 'properties' => []];
            }

            public function ejecutar(array $argumentos, array $contextoFijo = []): array
            {
                return ['status' => 'loop'];
            }
        };

        $client = new GeminiApiClient;
        $registry = new AsistenteToolRegistry([$toolMock]);
        $orquestador = new AsistenteOrquestadorService($client, $registry);

        $resultado = $orquestador->procesarConversacion('Prueba loop');

        $this->assertStringContainsString('límite de consultas', $resultado['respuesta']);
        $this->assertSame(['tool_infinita'], $resultado['herramientas_utilizadas']);
    }

    public function test_endpoint_chat_requiere_autenticacion(): void
    {
        $this->postJson('/api/v1/asistente/chat', ['mensaje' => 'Hola'])
            ->assertStatus(401);
    }

    public function test_endpoint_chat_valida_payload_requerido(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/asistente/chat', ['mensaje' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mensaje']);

        $this->postJson('/api/v1/asistente/chat', ['mensaje' => str_repeat('a', 1001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mensaje']);
    }

    public function test_endpoint_chat_procesa_mensaje_y_retorna_formato_estandar(): void
    {
        config(['gemini.api_key' => 'fake-test-key']);
        Sanctum::actingAs(User::factory()->create());

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'role' => 'model',
                            'parts' => [
                                ['text' => 'El asistente de la granja está en línea.'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/asistente/chat', [
            'mensaje' => '¿Estás disponible?',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'respuesta' => 'El asistente de la granja está en línea.',
                    'herramientas_utilizadas' => [],
                    'sugerencias_modulos' => [],
                ],
            ]);
    }

    public function test_modulo_navigation_resolver_resuelve_sugerencias_y_sanea_herramientas(): void
    {
        $resolver = new ModuloNavigationResolver;

        $textoConTool = 'Te sugiero utilizar `consultar_produccion_huevos` para revisar la postura de hoy.';
        $textoLimpio = $resolver->sanitizarRespuesta($textoConTool);

        $this->assertStringNotContainsString('consultar_produccion_huevos', $textoLimpio);
        $this->assertStringContainsString('Módulo de Producción', $textoLimpio);

        $sugerencias = $resolver->resolver($textoLimpio, ['consultar_produccion_huevos']);
        $this->assertNotEmpty($sugerencias);
        $this->assertSame('/produccion', $sugerencias[0]['ruta']);
        $this->assertSame('Ir al Módulo de Producción', $sugerencias[0]['titulo']);
    }

    public function test_endpoint_chat_retorna_error_controlado_si_falla_proveedor_ia(): void
    {
        config(['gemini.api_key' => 'fake-test-key']);
        Sanctum::actingAs(User::factory()->create());

        Http::fake([
            '*' => Http::response(['error' => 'API Unavailable'], 503),
        ]);

        $response = $this->postJson('/api/v1/asistente/chat', [
            'mensaje' => '¿Cuántas aves vivas tenemos hoy?',
        ]);

        $response->assertStatus(503)
            ->assertJson([
                'success' => false,
            ]);

        $this->assertStringContainsString('AviaCore está experimentando una pausa temporal', $response->json('message'));
        $this->assertStringNotContainsString('API Unavailable', $response->json('message'));
    }

    public function test_saludo_detector_service_responde_sin_consumir_tokens_ni_sugerir_modulos(): void
    {
        Sanctum::actingAs(User::factory()->create());

        // Aseguramos que NO se realice ninguna petición HTTP a Gemini
        Http::fake([
            '*' => Http::response(['error' => 'No debio llamar a Gemini'], 500),
        ]);

        $saludos = ['hola', 'Buenos días!', 'muchas gracias', '¿quién eres?'];

        foreach ($saludos as $saludo) {
            $response = $this->postJson('/api/v1/asistente/chat', [
                'mensaje' => $saludo,
            ]);

            $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'herramientas_utilizadas' => [],
                        'sugerencias_modulos' => [],
                    ],
                ]);

            $this->assertNotEmpty($response->json('data.respuesta'));
        }
    }

    public function test_modulo_navigation_resolver_no_sugiere_modulos_en_saludos_o_textos_genericos(): void
    {
        $resolver = new ModuloNavigationResolver;

        // Texto genérico o saludo que menciona palabras de la granja sin invitar a gestionar
        $textoBienvenida = '¡Hola! Soy AviaCore, tu asistente. ¿En qué te puedo ayudar hoy con el control de inventario, postura, alimentación o finanzas?';
        $sugerencias = $resolver->resolver($textoBienvenida, []);

        $this->assertEmpty($sugerencias, 'No debe sugerir módulos en saludos o textos de bienvenida genéricos');
    }
}
