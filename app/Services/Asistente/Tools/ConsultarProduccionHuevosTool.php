<?php

namespace App\Services\Asistente\Tools;

use App\Models\RegistroHuevo;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use Carbon\Carbon;

class ConsultarProduccionHuevosTool implements AsistenteToolInterface
{
    public function nombre(): string
    {
        return 'consultar_produccion_huevos';
    }

    public function descripcion(): string
    {
        return 'Consulta la producción de huevos (recolectados, rotos, aprovechables y porcentaje de rotura) en un periodo de tiempo o división.';
    }

    public function tablasConsultadas(): array
    {
        return ['mnt_registros_huevos'];
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
                    'description' => 'ID numérico de la división o corral para filtrar la postura.',
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

        $query = RegistroHuevo::query()
            ->whereBetween('fecha', [$desde->format('Y-m-d'), $hasta->format('Y-m-d')]);

        if (! empty($argumentos['id_division'])) {
            $query->where('id_division', (int) $argumentos['id_division']);
        }

        $registros = $query->orderBy('fecha', 'desc')->get();

        $totalRecolectados = (int) $registros->sum('cantidad_recolectada');
        $totalRotos = (int) $registros->sum('cantidad_rotos');
        $totalAprovechables = (int) $registros->sum('cantidad_aprovechables');

        $porcentajeRotura = $totalRecolectados > 0
            ? round(($totalRotos / $totalRecolectados) * 100, 1)
            : 0.0;

        // Desglose reciente de hasta 7 registros diarios
        $diario = $registros->take(7)->map(function (RegistroHuevo $r) {
            $fecha = $r->fecha instanceof Carbon ? $r->fecha : Carbon::parse($r->fecha);

            return [
                'fecha' => $fecha->format('d-m-Y'),
                'recolectados' => (int) $r->cantidad_recolectada,
                'rotos' => (int) $r->cantidad_rotos,
                'aprovechables' => (int) $r->cantidad_aprovechables,
            ];
        })->values()->all();

        return [
            'periodo' => [
                'desde' => $desde->format('d-m-Y'),
                'hasta' => $hasta->format('d-m-Y'),
            ],
            'total_recolectados' => $totalRecolectados,
            'total_rotos' => $totalRotos,
            'total_aprovechables' => $totalAprovechables,
            'porcentaje_rotura' => $porcentajeRotura,
            'resumen_diario_reciente' => $diario,
        ];
    }
}
