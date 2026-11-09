<?php

namespace App\Services\Asistente\Tools;

use App\Models\Gasto;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class ConsultarGastosPeriodoTool implements AsistenteToolInterface
{
    public function nombre(): string
    {
        return 'consultar_gastos_periodo';
    }

    public function descripcion(): string
    {
        return 'Consulta los gastos operativos y compras de la granja filtrando por mes, año, categoría o rango de fechas.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'mes' => [
                    'type' => 'INTEGER',
                    'description' => 'Número del mes a consultar (1 al 12).',
                ],
                'anio' => [
                    'type' => 'INTEGER',
                    'description' => 'Año a consultar (ej. 2026).',
                ],
                'fecha_inicio' => [
                    'type' => 'STRING',
                    'description' => 'Fecha de inicio del rango (formato Y-m-d). Opcional.',
                ],
                'fecha_fin' => [
                    'type' => 'STRING',
                    'description' => 'Fecha de fin del rango (formato Y-m-d). Opcional.',
                ],
                'categoria' => [
                    'type' => 'STRING',
                    'description' => 'Nombre o término de la categoría de gasto (ej. Alimento, Medicina, Mantenimiento).',
                ],
            ],
        ];
    }

    public function ejecutar(array $argumentos, array $contextoFijo = []): array
    {
        $timezone = config('avicola.timezone', 'America/El_Salvador');
        $query = Gasto::query()->with('categoria');

        if (! empty($argumentos['fecha_inicio'])) {
            $desde = Carbon::parse($argumentos['fecha_inicio'], $timezone)->startOfDay();
            $hasta = ! empty($argumentos['fecha_fin'])
                ? Carbon::parse($argumentos['fecha_fin'], $timezone)->endOfDay()
                : (clone $desde)->endOfDay();
            $query->whereBetween('fecha', [$desde->format('Y-m-d'), $hasta->format('Y-m-d')]);
        } elseif (! empty($argumentos['mes'])) {
            $anio = (int) ($argumentos['anio'] ?? Carbon::now($timezone)->year);
            $mes = (int) $argumentos['mes'];
            $desde = Carbon::createFromDate($anio, $mes, 1, $timezone)->startOfMonth();
            $hasta = (clone $desde)->endOfMonth();
            $query->whereBetween('fecha', [$desde->format('Y-m-d'), $hasta->format('Y-m-d')]);
        }

        if (! empty($argumentos['categoria'])) {
            $cat = trim($argumentos['categoria']);
            $query->whereHas('categoria', function (Builder $q) use ($cat) {
                $q->whereRaw('LOWER(nombre) LIKE ?', ['%'.mb_strtolower($cat).'%']);
            });
        }

        $gastos = $query->orderBy('fecha', 'desc')->get();

        $totalGastado = round((float) $gastos->sum('monto'), 2);

        $porCategoria = [];
        foreach ($gastos as $g) {
            $catNombre = $g->categoria?->nombre ?? 'Sin categoría';
            $porCategoria[$catNombre] = round(($porCategoria[$catNombre] ?? 0.0) + (float) $g->monto, 2);
        }

        $ultimosGastos = $gastos->take(5)->map(function (Gasto $g) {
            $fecha = $g->fecha instanceof Carbon ? $g->fecha : Carbon::parse($g->fecha);

            return [
                'fecha' => $fecha->format('d-m-Y'),
                'monto' => round((float) $g->monto, 2),
                'categoria' => $g->categoria?->nombre ?? 'Sin categoría',
                'concepto' => $g->concepto,
                'proveedor' => $g->proveedor,
            ];
        })->values()->all();

        return [
            'total_gastado' => $totalGastado,
            'por_categoria' => $porCategoria,
            'ultimos_gastos' => $ultimosGastos,
        ];
    }
}

