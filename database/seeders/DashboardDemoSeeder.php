<?php

namespace Database\Seeders;

use App\Models\Ave;
use App\Models\Casita;
use App\Models\CtlCategoriaGasto;
use App\Models\CtlTipoAlimento;
use App\Models\Division;
use App\Models\Gasto;
use App\Models\ObservacionAve;
use App\Models\RegistroAlimentacion;
use App\Models\RegistroHuevo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * Historial de demostración para el dashboard analítico (Etapa 6).
 *
 * NO forma parte de DatabaseSeeder: se ejecuta a demanda con
 *   php artisan db:seed --class=DashboardDemoSeeder
 *
 * Es determinista (sin azar) e idempotente: completa 30 días de huevos y
 * alimentación solo donde falta el registro, así que puede correrse varias veces.
 * Incluye días sin registro (para ver el relleno con 0) y un pico de rotura
 * entre hace 3 y 6 días (para activar la alerta de rotura alta).
 */
class DashboardDemoSeeder extends Seeder
{
    private const DIAS_HISTORIAL = 30;

    /** Días atrás sin registro de huevos ni alimentación. */
    private const DIAS_SIN_REGISTRO = [9, 17, 25];

    /** Rango (días atrás) con rotura alta en la división 1 de la casita 1. */
    private const PICO_ROTURA = [3, 6];

    public function run(): void
    {
        $usuario = User::first();
        $hoy = CarbonImmutable::now(config('avicola.timezone'))->startOfDay();

        $this->sembrarProduccion($usuario, $hoy);
        $this->sembrarGastos($usuario, $hoy);
        $this->sembrarObservaciones($usuario, $hoy);
    }

    private function sembrarProduccion(?User $usuario, CarbonImmutable $hoy): void
    {
        $casita1 = Casita::where('nombre', 'Casita 1')->first();
        $divisiones = Division::where('id_casita', $casita1?->id)->orderBy('id')->get();
        $alimento = CtlTipoAlimento::where('nombre', 'Concentrado Postura Comercial')->first();

        foreach ($divisiones->take(2)->values() as $indice => $division) {
            for ($atras = 0; $atras < self::DIAS_HISTORIAL; $atras++) {
                if (in_array($atras, self::DIAS_SIN_REGISTRO, true)) {
                    continue;
                }

                $fecha = $hoy->subDays($atras);

                $recolectados = 6 + (($atras * 7 + $indice * 3) % 4) - $indice;
                $rotos = $this->rotos($indice, $atras);
                $this->crearUnaVez(RegistroHuevo::class, [
                    'id_division' => $division->id,
                    'fecha' => $fecha->toDateString(),
                ], [
                    'id_usuario' => $usuario?->id,
                    'cantidad_recolectada' => $recolectados,
                    'cantidad_rotos' => min($rotos, $recolectados),
                    'observaciones' => 'Registro de demostración.',
                ], $fecha);

                if ($alimento) {
                    $this->crearUnaVez(RegistroAlimentacion::class, [
                        'id_division' => $division->id,
                        'id_tipo_alimento' => $alimento->id,
                        'fecha' => $fecha->toDateString(),
                    ], [
                        'id_usuario' => $usuario?->id,
                        'hora' => '07:30:00',
                        'cantidad' => 1.5 + (($atras + $indice) % 3) * 0.25,
                        'unidad_medida' => 'kg',
                        'costo_total' => 1.25,
                        'observaciones' => 'Registro de demostración.',
                    ], $fecha);
                }
            }
        }
    }

    private function rotos(int $indiceDivision, int $atras): int
    {
        [$desde, $hasta] = self::PICO_ROTURA;

        if ($indiceDivision === 0 && $atras >= $desde && $atras <= $hasta) {
            return 3; // ~35 % de rotura en esos días
        }

        return $atras % 11 === 0 ? 1 : 0;
    }

    private function sembrarGastos(?User $usuario, CarbonImmutable $hoy): void
    {
        $gastos = [
            ['Alimento y Forraje', 4, 41.00, 'Saco de maíz amarillo quebrado', 'Agroveterinaria La Campiña'],
            ['Medicina y Sanidad', 9, 18.75, 'Antiparasitario para gallinas', 'Farmacia Veterinaria Central'],
            ['Insumos y Limpieza', 12, 9.40, 'Desinfectante para comederos', 'Ferretería El Agricultor'],
            // Mes anterior: no debe sumar al total del mes actual.
            ['Alimento y Forraje', 38, 52.00, 'Concentrado postura (mes anterior)', 'Agroveterinaria La Campiña'],
        ];

        foreach ($gastos as [$categoria, $atras, $monto, $concepto, $proveedor]) {
            $cat = CtlCategoriaGasto::where('nombre', $categoria)->first();
            if (! $cat) {
                continue;
            }
            $fecha = $hoy->subDays($atras);

            $this->crearUnaVez(Gasto::class, [
                'id_categoria_gasto' => $cat->id,
                'fecha' => $fecha->toDateString(),
                'concepto' => $concepto,
            ], [
                'id_usuario' => $usuario?->id,
                'monto' => $monto,
                'proveedor' => $proveedor,
            ], $fecha);
        }
    }

    private function sembrarObservaciones(?User $usuario, CarbonImmutable $hoy): void
    {
        $aves = Ave::activa()->orderBy('id')->take(2)->get();

        $observaciones = [
            [5, 'Salud', 'Control de plumaje', 'Plumaje en buen estado, sin parásitos visibles.'],
            [2, 'Comportamiento', 'Ave retraída', 'Se aisló del grupo durante la mañana; se mantiene en observación.'],
        ];

        foreach ($observaciones as $i => [$atras, $tipo, $titulo, $descripcion]) {
            $ave = $aves[$i] ?? null;
            if (! $ave) {
                continue;
            }
            $fecha = $hoy->subDays($atras);

            $this->crearUnaVez(ObservacionAve::class, [
                'id_ave' => $ave->id,
                'fecha' => $fecha->toDateString(),
                'titulo' => $titulo,
            ], [
                'id_usuario' => $usuario?->id,
                'tipo' => $tipo,
                'descripcion' => $descripcion,
            ], $fecha);
        }
    }

    /**
     * Crea el registro solo si no existe, fijando created_at al día del
     * registro (08:00 hora local) para que la actividad reciente sea coherente.
     *
     * @param  class-string<Model>  $modelo
     */
    private function crearUnaVez(string $modelo, array $claves, array $valores, CarbonImmutable $fecha): void
    {
        $registro = $modelo::firstOrNew($claves, $valores);

        if ($registro->exists) {
            return;
        }

        $momento = $fecha->setTime(8, 0)->utc();
        $registro->created_at = $momento;
        $registro->updated_at = $momento;
        $registro->save();
    }
}
