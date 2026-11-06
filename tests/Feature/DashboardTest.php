<?php

namespace Tests\Feature;

use App\Models\Ave;
use App\Models\CtlCategoriaGasto;
use App\Models\CtlEspecie;
use App\Models\CtlSexo;
use App\Models\CtlTipoAlimento;
use App\Models\Division;
use App\Models\Gasto;
use App\Models\MovimientoAve;
use App\Models\ObservacionAve;
use App\Models\RegistroAlimentacion;
use App\Models\RegistroHuevo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogoAvesSeeder;
use Database\Seeders\InstalacionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function sembrarBase(): void
    {
        $this->seed(CatalogoAvesSeeder::class);
        $this->seed(InstalacionesSeeder::class);
    }

    private function crearAve(string $especie, string $sexo, ?Division $division, string $estado = 'activa'): Ave
    {
        static $n = 0;
        $n++;

        return Ave::create([
            'id_division' => $division?->id,
            'id_especie' => CtlEspecie::where('nombre', $especie)->value('id'),
            'id_sexo' => CtlSexo::where('nombre', $sexo)->value('id'),
            'codigo' => 'T-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'estado' => $estado,
        ]);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/dashboard/resumen')->assertStatus(401);
    }

    public function test_dias_invalido_devuelve_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/dashboard/resumen?dias=15')->assertStatus(422);
        $this->getJson('/api/v1/dashboard/resumen?dias=abc')->assertStatus(422);
    }

    public function test_dias_valido_responde_exito(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/dashboard/resumen?dias=30')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    public function test_aves_cuenta_solo_activas_por_especie_y_sexo(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $div = Division::first();

        $this->crearAve('Gallina', 'Hembra', $div);
        $this->crearAve('Gallina', 'Macho', $div);
        $this->crearAve('Pato', 'Hembra', $div);
        $this->crearAve('Gallina', 'Hembra', $div, 'fallecida');
        $this->crearAve('Pato', 'Macho', $div, 'salida');

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();

        $res->assertJsonPath('data.aves.total', 3);
        $porEspecie = collect($res->json('data.aves.por_especie'))->pluck('total', 'especie');
        $this->assertSame(2, $porEspecie['Gallina']);
        $this->assertSame(1, $porEspecie['Pato']);
        $this->assertSame(3, collect($res->json('data.aves.por_especie'))->sum('total'));
        $this->assertSame(3, collect($res->json('data.aves.por_sexo'))->sum('total'));
    }

    public function test_aves_base_vacia_devuelve_ceros(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/dashboard/resumen')
            ->assertOk()
            ->assertJsonPath('data.aves.total', 0)
            ->assertJsonPath('data.aves.por_especie', [])
            ->assertJsonPath('data.aves.por_sexo', [])
            ->assertJsonPath('data.ocupacion', []);
    }

    public function test_ocupacion_incluye_divisiones_vacias_e_inactivas(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $divisiones = Division::orderBy('id')->get();
        $conAves = $divisiones[0];
        $inactiva = $divisiones[1];
        $inactiva->update(['activa' => false]);

        $this->crearAve('Gallina', 'Hembra', $conAves);
        $this->crearAve('Gallina', 'Macho', $conAves);

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();

        $todas = collect($res->json('data.ocupacion'))->flatMap(fn ($c) => $c['divisiones'])->keyBy('id_division');
        $this->assertCount($divisiones->count(), $todas);
        $this->assertSame(2, $todas[$conAves->id]['total_aves']);
        $this->assertSame(['Gallina'], $todas[$conAves->id]['especies']);
        $this->assertSame(0, $todas[$inactiva->id]['total_aves']);
        $this->assertFalse($todas[$inactiva->id]['activa']);

        foreach ($res->json('data.ocupacion') as $casita) {
            $this->assertSame(collect($casita['divisiones'])->sum('total_aves'), $casita['total_aves']);
        }
    }

    public function test_ocupacion_no_cuenta_aves_no_activas(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $div = Division::first();

        $this->crearAve('Gallina', 'Hembra', $div);
        $this->crearAve('Gallina', 'Hembra', $div, 'fallecida');
        $this->crearAve('Pato', 'Hembra', $div, 'trasladada');

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();
        $fila = collect($res->json('data.ocupacion'))->flatMap(fn ($c) => $c['divisiones'])->firstWhere('id_division', $div->id);

        $this->assertSame(1, $fila['total_aves']);
        $this->assertSame(['Gallina'], $fila['especies']);
    }

    private function fijarAhora(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
    }

    private function crearHuevos(Division $division, string $fecha, int $recolectados, int $rotos = 0): RegistroHuevo
    {
        return RegistroHuevo::create([
            'id_division' => $division->id,
            'fecha' => $fecha,
            'cantidad_recolectada' => $recolectados,
            'cantidad_rotos' => $rotos,
        ]);
    }

    public function test_huevos_serie_rellena_dias_sin_registro_con_cero(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $this->crearHuevos(Division::first(), '2026-11-04', 10, 2);

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();
        $serie = collect($res->json('data.huevos.serie'));

        $this->assertCount(7, $serie);
        $this->assertSame('2026-10-31', $serie->first()['fecha']);
        $this->assertSame('2026-11-06', $serie->last()['fecha']);
        $this->assertSame(10, $serie->firstWhere('fecha', '2026-11-04')['recolectados']);
        $this->assertSame(8, $serie->firstWhere('fecha', '2026-11-04')['aprovechables']);
        $this->assertSame(2, $serie->firstWhere('fecha', '2026-11-04')['rotos']);
        $this->assertSame(6, $serie->where('aprovechables', 0)->count());
    }

    public function test_huevos_totales_coinciden_con_tabla(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();
        $this->crearHuevos($div, '2026-11-06', 12, 2);
        $this->crearHuevos($div, '2026-11-05', 9, 1);
        $this->crearHuevos($div, '2026-10-01', 50, 5); // fuera del rango de 7 días

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();

        $res->assertJsonPath('data.huevos.rango.dias', 7)
            ->assertJsonPath('data.huevos.rango.recolectados', 21)
            ->assertJsonPath('data.huevos.rango.rotos', 3)
            ->assertJsonPath('data.huevos.rango.aprovechables', 18)
            ->assertJsonPath('data.huevos.hoy.recolectados', 12)
            ->assertJsonPath('data.huevos.hoy.aprovechables', 10);
        $this->assertSame(
            (int) RegistroHuevo::whereBetween('fecha', ['2026-10-31', '2026-11-06'])->sum('cantidad_aprovechables'),
            $res->json('data.huevos.rango.aprovechables'),
        );
    }

    public function test_porcentaje_rotura(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');

        $this->getJson('/api/v1/dashboard/resumen')
            ->assertOk()
            ->assertJsonPath('data.huevos.rango.porcentaje_rotura', 0);

        $this->crearHuevos(Division::first(), '2026-11-06', 10, 2);

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();
        $this->assertEquals(20.0, $res->json('data.huevos.rango.porcentaje_rotura'));
    }

    public function test_huevos_hoy_usa_zona_horaria_de_negocio(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        // 05:30 UTC del 06 = 23:30 del 05 en America/El_Salvador (UTC-6)
        $this->fijarAhora('2026-11-06 05:30:00');
        $this->crearHuevos(Division::first(), '2026-11-05', 7, 1);

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();

        $res->assertJsonPath('data.huevos.hoy.recolectados', 7);
        $this->assertSame('2026-11-05', collect($res->json('data.huevos.serie'))->last()['fecha']);
    }

    public function test_ultimos_7_y_30_son_independientes_de_dias(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();
        $this->crearHuevos($div, '2026-10-27', 8);  // hace 10 días
        $this->crearHuevos($div, '2026-11-05', 5);  // ayer

        $siete = $this->getJson('/api/v1/dashboard/resumen?dias=7')->assertOk();
        $treinta = $this->getJson('/api/v1/dashboard/resumen?dias=30')->assertOk();

        foreach ([$siete, $treinta] as $res) {
            $res->assertJsonPath('data.huevos.ultimos_7.aprovechables', 5)
                ->assertJsonPath('data.huevos.ultimos_30.aprovechables', 13);
        }
        $siete->assertJsonPath('data.huevos.rango.aprovechables', 5);
        $treinta->assertJsonPath('data.huevos.rango.aprovechables', 13);
        $this->assertCount(30, $treinta->json('data.huevos.serie'));
    }

    private function crearAlimento(Division $division, string $fecha, float $cantidad, string $unidad = 'kg'): RegistroAlimentacion
    {
        $tipo = CtlTipoAlimento::firstOrCreate(['nombre' => 'Maíz'], ['unidad_medida_defecto' => 'kg', 'activo' => true]);

        return RegistroAlimentacion::create([
            'id_division' => $division->id,
            'id_tipo_alimento' => $tipo->id,
            'fecha' => $fecha,
            'cantidad' => $cantidad,
            'unidad_medida' => $unidad,
        ]);
    }

    private function crearGasto(string $categoria, string $fecha, float $monto): Gasto
    {
        $cat = CtlCategoriaGasto::firstOrCreate(['nombre' => $categoria], ['activa' => true]);

        return Gasto::create([
            'id_categoria_gasto' => $cat->id,
            'fecha' => $fecha,
            'monto' => $monto,
            'concepto' => 'Compra de prueba',
        ]);
    }

    public function test_kilos_ignora_unidades_distintas_de_kg(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();
        $this->crearAlimento($div, '2026-11-06', 5, 'kg');
        $this->crearAlimento($div, '2026-11-06', 3, 'lb');

        $this->getJson('/api/v1/dashboard/resumen')
            ->assertOk()
            ->assertJsonPath('data.alimentacion.kilos_mes', 5)
            ->assertJsonPath('data.alimentacion.kilos_hoy', 5);
    }

    public function test_kilos_hoy_vs_mes(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();
        $this->crearAlimento($div, '2026-11-06', 2.5);
        $this->crearAlimento($div, '2026-11-02', 4);
        $this->crearAlimento($div, '2026-10-30', 9); // mes anterior

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();

        $this->assertEquals(2.5, $res->json('data.alimentacion.kilos_hoy'));
        $this->assertEquals(6.5, $res->json('data.alimentacion.kilos_mes'));
    }

    public function test_alimentacion_y_gastos_base_vacia(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/dashboard/resumen')
            ->assertOk()
            ->assertJsonPath('data.alimentacion.kilos_mes', 0)
            ->assertJsonPath('data.alimentacion.kilos_hoy', 0)
            ->assertJsonPath('data.gastos.total_mes', 0)
            ->assertJsonPath('data.gastos.por_categoria', []);
    }

    public function test_gastos_mes_excluye_otros_meses_y_agrupa_por_categoria(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->fijarAhora('2026-11-06 18:00:00');
        $this->crearGasto('Alimento', '2026-11-01', 40.50);
        $this->crearGasto('Alimento', '2026-11-05', 10);
        $this->crearGasto('Medicina', '2026-11-03', 25);
        $this->crearGasto('Medicina', '2026-10-31', 500); // mes anterior

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();

        $this->assertEquals(75.5, $res->json('data.gastos.total_mes'));
        $porCategoria = collect($res->json('data.gastos.por_categoria'));
        $this->assertEquals(75.5, $porCategoria->sum('total'));
        $this->assertSame(['Alimento', 'Medicina'], $porCategoria->pluck('categoria')->all());
        $this->assertEquals(50.5, $porCategoria->first()['total']);
    }

    private function codigosAlertas($res): array
    {
        return collect($res->json('data.alertas'))->pluck('codigo')->sort()->values()->all();
    }

    public function test_sin_alertas_en_base_vacia(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();

        $this->assertSame([], $res->json('data.alertas'));
        // La actividad se pagina en su propio endpoint: ya no viaja en el resumen.
        $this->assertArrayNotHasKey('actividad', $res->json('data'));
    }

    public function test_alertas_de_ausencia_solo_si_hay_aves_activas(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();

        // Solo aves no activas: sin alertas de ausencia.
        $this->crearAve('Gallina', 'Hembra', $div, 'fallecida');
        $this->assertSame([], $this->getJson('/api/v1/dashboard/resumen')->json('data.alertas'));

        // Con un ave activa y sin registros hoy: ambas alertas.
        $this->crearAve('Gallina', 'Hembra', $div);
        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();
        $this->assertSame(['sin_alimentacion_hoy', 'sin_postura_hoy'], $this->codigosAlertas($res));
        $this->assertSame('warning', $res->json('data.alertas.0.nivel'));
        $this->assertNotEmpty($res->json('data.alertas.0.mensaje'));

        // Registros de hoy las resuelven.
        $this->crearHuevos($div, '2026-11-06', 10, 0);
        $this->crearAlimento($div, '2026-11-06', 2);
        $this->assertSame([], $this->getJson('/api/v1/dashboard/resumen')->json('data.alertas'));
    }

    public function test_alerta_rotura_alta_sobre_umbral(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();

        // 10% exacto: no alerta.
        $this->crearHuevos($div, '2026-11-06', 100, 10);
        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();
        $this->assertNotContains('rotura_alta', $this->codigosAlertas($res));

        // 11%: alerta.
        $this->crearHuevos($div, '2026-11-05', 100, 12); // total 200 / 22 rotos = 11%
        $res = $this->getJson('/api/v1/dashboard/resumen')->assertOk();
        $this->assertContains('rotura_alta', $this->codigosAlertas($res));
    }

    public function test_rotura_alta_ignora_registros_fuera_de_7_dias(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();

        $this->crearHuevos($div, '2026-10-20', 100, 50); // fuera de la ventana
        $this->crearHuevos($div, '2026-11-06', 100, 2);

        $res = $this->getJson('/api/v1/dashboard/resumen?dias=30')->assertOk();
        $this->assertNotContains('rotura_alta', $this->codigosAlertas($res));
    }

    /**
     * Crea $cantidad eventos (huevos, gasto, traslado y observación, en ese
     * orden rotativo), el i-ésimo registrado hace i minutos.
     */
    private function crearActividad(int $cantidad): void
    {
        $this->sembrarBase();
        $this->fijarAhora('2026-11-06 18:00:00');
        $div = Division::first();
        $destino = Division::orderByDesc('id')->first();
        $ave = $this->crearAve('Gallina', 'Hembra', $div);

        $momento = fn (int $minutosAtras) => CarbonImmutable::now('UTC')->subMinutes($minutosAtras);
        $eventos = [
            fn ($m) => $this->crearHuevos($div, '2026-11-06', 5)->forceFill(['created_at' => $m]),
            fn ($m) => $this->crearGasto('Alimento', '2026-11-06', 3)->forceFill(['created_at' => $m]),
            fn ($m) => MovimientoAve::create([
                'id_ave' => $ave->id, 'id_division_origen' => $div->id, 'id_division_destino' => $destino->id,
                'fecha' => $m, 'motivo' => 'Prueba',
            ])->forceFill(['created_at' => $m]),
            fn ($m) => ObservacionAve::create([
                'id_ave' => $ave->id, 'fecha' => '2026-11-06', 'tipo' => 'Salud',
                'titulo' => 'Revisión', 'descripcion' => 'Todo bien',
            ])->forceFill(['created_at' => $m]),
        ];
        foreach (range(1, $cantidad) as $i) {
            $eventos[($i - 1) % 4]($momento($i))->saveQuietly();
        }
    }

    public function test_actividad_requiere_autenticacion(): void
    {
        $this->getJson('/api/v1/dashboard/actividad')->assertStatus(401);
    }

    public function test_actividad_base_vacia_devuelve_pagina_vacia(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/dashboard/actividad')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonPath('meta.from', 0)
            ->assertJsonPath('meta.to', 0);
    }

    public function test_actividad_mezcla_tipos_y_ordena_del_mas_reciente_al_mas_antiguo(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->crearActividad(12);

        $res = $this->getJson('/api/v1/dashboard/actividad?per_page=20')->assertOk();
        $actividad = collect($res->json('data'));

        $this->assertCount(12, $actividad);
        $this->assertSame(['gasto', 'huevos', 'observacion', 'traslado'], $actividad->pluck('tipo')->unique()->sort()->values()->all());
        $fechas = $actividad->pluck('fecha')->all();
        $ordenadas = $fechas;
        rsort($ordenadas);
        $this->assertSame($ordenadas, $fechas);
        // 18:00 UTC = 12:00 en El Salvador; el más reciente es 1 minuto antes.
        $this->assertSame('2026-11-06 11:59', $fechas[0]);
        $this->assertNotEmpty($actividad->first()['descripcion']);
    }

    public function test_actividad_pagina_en_el_servidor_sin_repetir_eventos(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->crearActividad(12);

        $p1 = $this->getJson('/api/v1/dashboard/actividad?per_page=5&page=1')->assertOk();
        $p2 = $this->getJson('/api/v1/dashboard/actividad?per_page=5&page=2')->assertOk();
        $p3 = $this->getJson('/api/v1/dashboard/actividad?per_page=5&page=3')->assertOk();

        $this->assertCount(5, $p1->json('data'));
        $this->assertCount(5, $p2->json('data'));
        $this->assertCount(2, $p3->json('data'));
        $p1->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.from', 1)
            ->assertJsonPath('meta.to', 5);
        $p3->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('meta.from', 11)
            ->assertJsonPath('meta.to', 12);

        $fechas = collect([$p1, $p2, $p3])->flatMap(fn ($p) => collect($p->json('data'))->pluck('fecha'));
        $this->assertCount(12, $fechas->unique());
    }

    public function test_actividad_pagina_fuera_de_rango_devuelve_lista_vacia(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->crearActividad(4);

        $this->getJson('/api/v1/dashboard/actividad?per_page=5&page=9')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_actividad_limita_el_historial_a_50_eventos(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->crearActividad(60);

        $this->getJson('/api/v1/dashboard/actividad?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 50)
            ->assertJsonPath('meta.last_page', 3);
    }

    public function test_actividad_parametros_invalidos_devuelven_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/dashboard/actividad?per_page=7')->assertStatus(422);
        $this->getJson('/api/v1/dashboard/actividad?page=0')->assertStatus(422);
        $this->getJson('/api/v1/dashboard/actividad?page=abc')->assertStatus(422);
    }
}
