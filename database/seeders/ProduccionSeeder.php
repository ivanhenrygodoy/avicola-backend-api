<?php

namespace Database\Seeders;

use App\Models\Casita;
use App\Models\CtlTipoAlimento;
use App\Models\Division;
use App\Models\RegistroAlimentacion;
use App\Models\RegistroHuevo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProduccionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $usuario = User::first();
        $casita1 = Casita::where('nombre', 'Casita 1')->first();
        $casita2 = Casita::where('nombre', 'Casita 2')->first();

        $c1d1 = Division::where('id_casita', $casita1?->id)->where('nombre', 'División 1')->first();
        $c1d2 = Division::where('id_casita', $casita1?->id)->where('nombre', 'División 2')->first();
        $c2d1 = Division::where('id_casita', $casita2?->id)->where('nombre', 'División 1')->first();

        $alimentoPostura = CtlTipoAlimento::where('nombre', 'Concentrado Postura Comercial')->first();
        $alimentoMaiz = CtlTipoAlimento::where('nombre', 'Maíz Amarillo Quebrado')->first();
        $alimentoCrecimiento = CtlTipoAlimento::where('nombre', 'Concentrado Crecimiento y Engorde')->first();

        $hoy = Carbon::today();

        // 1. Registros de Recolección de Huevos (Últimos 3 días en corrales de gallinas ponedoras)
        if ($c1d1) {
            RegistroHuevo::firstOrCreate(
                ['id_division' => $c1d1->id, 'fecha' => $hoy->copy()->subDays(2)->toDateString()],
                [
                    'id_usuario' => $usuario?->id,
                    'cantidad_recolectada' => 6,
                    'cantidad_rotos' => 0,
                    'cantidad_aprovechables' => 6,
                    'observaciones' => 'Postura matutina normal. Excelente calidad de cáscara.',
                ]
            );

            RegistroHuevo::firstOrCreate(
                ['id_division' => $c1d1->id, 'fecha' => $hoy->copy()->subDays(1)->toDateString()],
                [
                    'id_usuario' => $usuario?->id,
                    'cantidad_recolectada' => 7,
                    'cantidad_rotos' => 1,
                    'cantidad_aprovechables' => 6,
                    'observaciones' => 'Un huevo roto en el nido de la esquina.',
                ]
            );

            RegistroHuevo::firstOrCreate(
                ['id_division' => $c1d1->id, 'fecha' => $hoy->toDateString()],
                [
                    'id_usuario' => $usuario?->id,
                    'cantidad_recolectada' => 8,
                    'cantidad_rotos' => 0,
                    'cantidad_aprovechables' => 8,
                    'observaciones' => 'Recolección del día completada.',
                ]
            );
        }

        if ($c1d2) {
            RegistroHuevo::firstOrCreate(
                ['id_division' => $c1d2->id, 'fecha' => $hoy->copy()->subDays(1)->toDateString()],
                [
                    'id_usuario' => $usuario?->id,
                    'cantidad_recolectada' => 5,
                    'cantidad_rotos' => 0,
                    'cantidad_aprovechables' => 5,
                    'observaciones' => 'Postura estable.',
                ]
            );

            RegistroHuevo::firstOrCreate(
                ['id_division' => $c1d2->id, 'fecha' => $hoy->toDateString()],
                [
                    'id_usuario' => $usuario?->id,
                    'cantidad_recolectada' => 6,
                    'cantidad_rotos' => 0,
                    'cantidad_aprovechables' => 6,
                    'observaciones' => 'Nidos limpios y con viruta fresca.',
                ]
            );
        }

        // 2. Registros de Alimentación (Últimos 2 días)
        if ($c1d1 && $alimentoPostura) {
            RegistroAlimentacion::firstOrCreate(
                ['id_division' => $c1d1->id, 'fecha' => $hoy->copy()->subDays(1)->toDateString(), 'id_tipo_alimento' => $alimentoPostura->id],
                [
                    'id_usuario' => $usuario?->id,
                    'hora' => '07:30:00',
                    'cantidad' => 1.50,
                    'unidad_medida' => 'kg',
                    'costo_total' => 1.25,
                    'observaciones' => 'Ración de la mañana suministrada en comederos tolva.',
                ]
            );

            RegistroAlimentacion::firstOrCreate(
                ['id_division' => $c1d1->id, 'fecha' => $hoy->toDateString(), 'id_tipo_alimento' => $alimentoPostura->id],
                [
                    'id_usuario' => $usuario?->id,
                    'hora' => '07:30:00',
                    'cantidad' => 1.50,
                    'unidad_medida' => 'kg',
                    'costo_total' => 1.25,
                    'observaciones' => 'Comederos limpios antes de suministrar.',
                ]
            );
        }

        if ($c2d1 && $alimentoCrecimiento) {
            RegistroAlimentacion::firstOrCreate(
                ['id_division' => $c2d1->id, 'fecha' => $hoy->toDateString(), 'id_tipo_alimento' => $alimentoCrecimiento->id],
                [
                    'id_usuario' => $usuario?->id,
                    'hora' => '08:00:00',
                    'cantidad' => 2.00,
                    'unidad_medida' => 'kg',
                    'costo_total' => 1.80,
                    'observaciones' => 'Ración de crecimiento para lote de chompipes.',
                ]
            );
        }
    }
}

