<?php

namespace App\Services\Asistente\Tools;

use App\Models\RegistroAlimentacion;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ConsultarRacionesAlimentoTool implements AsistenteToolInterface
{
    public function nombre(): string
    {
        return 'consultar_raciones_alimento';
    }

    public function descripcion(): string
    {
        return 'Consulta las raciones de alimento suministradas a las divisiones/corrales (kilos y costos acumulados).';
    }

    public function esquema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'dias' => [
                    'type' => 'INTEGER',
                    'description' => 'Número de días hacia atrás a consultar (ej. 7 o 30). Por defecto 7.',
                ],
                'fecha_inicio' => [
                    'type' => 'STRING',
                    'description' => 'Fecha de inicio del rango (formato Y-m-d). Opcional.',
                ],
                'fecha_fin' => [
                    'type' => 'STRING',
                    'description' => 'Fecha de fin del rango (formato Y-m-d). Opcional.',
                ],
                'id_division' => [
                    'type' => 'INTEGER',
                    'description' => 'ID de la división o corral para filtrar el alimento.',
                ],
                'tipo_alimento' => [
                    'type' => 'STRING',
                    'description' => 'Nombre o tipo de alimento a filtrar (ej. Postura, Maíz, Concentrado).',
                ],
            ],
        ];
    }

    public function ejecutar(array $argumentos, array $contextoFijo = []): array
    {
        $timezone = config('avicola.timezone', 'America/El_Salvador');
        $hoy = Carbon::now($timezone)->endOfDay();

        if (! empty($argumentos['fecha_inicio'])) {
            $desde = Carbon::parse($argumentos['fecha_inicio'], $timezone)->startOfDay();
            $hasta = ! empty($argumentos['fecha_fin'])
                ? Carbon::parse($argumentos['fecha_fin'], $timezone)->endOfDay()
                : (clone $desde)->endOfDay();
        } else {
            $dias = max(1, (int) ($argumentos['dias'] ?? 7));
            $desde = (clone $hoy)->subDays($dias - 1)->startOfDay();
            $hasta = $hoy;
        }

        $query = RegistroAlimentacion::query()
            ->with(['tipoAlimento', 'division.casita'])
            ->whereBetween('fecha', [$desde->format('Y-m-d'), $hasta->format('Y-m-d')]);

        if (! empty($argumentos['id_division'])) {
            $query->where('id_division', (int) $argumentos['id_division']);
        }

        if (! empty($argumentos['tipo_alimento'])) {
            $tipo = trim($argumentos['tipo_alimento']);
            $query->whereHas('tipoAlimento', function (Builder $q) use ($tipo) {
                $q->whereRaw('LOWER(nombre) LIKE ?', ['%'.mb_strtolower($tipo).'%']);
            });
        }

        $registros = $query->orderBy('fecha', 'desc')->get();

        $totalKilos = 0.0;
        $totalCosto = 0.0;
        $porTipo = [];

        foreach ($registros as $r) {
            $kilos = (float) $r->cantidad;
            $costo = (float) ($r->costo_total ?? 0.0);

            $totalKilos += $kilos;
            $totalCosto += $costo;

            $tipoNombre = $r->tipoAlimento?->nombre ?? 'Sin tipo';
            $porTipo[$tipoNombre] = round(($porTipo[$tipoNombre] ?? 0.0) + $kilos, 2);
        }

        $ultimos = $registros->take(5)->map(function (RegistroAlimentacion $r) {
            $fecha = $r->fecha instanceof Carbon ? $r->fecha : Carbon::parse($r->fecha);
            $division = $r->division?->nombre ?? 'Corral sin asignar';

            return [
                'fecha' => $fecha->format('d-m-Y'),
                'division' => $division,
                'tipo_alimento' => $r->tipoAlimento?->nombre ?? 'Sin tipo',
                'cantidad_kg' => round((float) $r->cantidad, 2),
                'costo' => $r->costo_total ? round((float) $r->costo_total, 2) : null,
            ];
        })->values()->all();

        return [
            'total_kilos' => round($totalKilos, 2),
            'total_costo' => round($totalCosto, 2),
            'por_tipo_alimento' => $porTipo,
            'ultimos_suministros' => $ultimos,
        ];
    }
}

