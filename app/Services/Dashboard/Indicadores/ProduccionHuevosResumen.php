<?php

namespace App\Services\Dashboard\Indicadores;

use App\Models\RegistroHuevo;
use App\Services\Dashboard\Contracts\IndicadorDashboard;
use App\Services\Dashboard\PeriodoDashboard;
use Illuminate\Support\Collection;

/**
 * Producción de huevos: hoy, rango seleccionado, últimos 7 y 30 días
 * y serie diaria. Una sola consulta agrupada por fecha.
 */
class ProduccionHuevosResumen implements IndicadorDashboard
{
    private const VENTANA_MAXIMA = 30;

    public function clave(): string
    {
        return 'huevos';
    }

    public function calcular(PeriodoDashboard $periodo): array
    {
        $inicioVentana = $periodo->hoy->subDays(self::VENTANA_MAXIMA - 1);

        /** @var Collection<string, object> $porDia */
        $porDia = RegistroHuevo::query()
            ->whereBetween('fecha', [$inicioVentana->toDateString(), $periodo->hoy->toDateString()])
            ->groupBy('fecha')
            ->selectRaw('fecha, sum(cantidad_recolectada) as recolectados, sum(cantidad_rotos) as rotos, sum(cantidad_aprovechables) as aprovechables')
            ->get()
            ->keyBy(fn ($fila) => substr((string) $fila->getRawOriginal('fecha'), 0, 10));

        $dias = collect(range(self::VENTANA_MAXIMA - 1, 0))->map(function (int $atras) use ($periodo, $porDia) {
            $fecha = $periodo->hoy->subDays($atras)->toDateString();
            $fila = $porDia->get($fecha);

            return [
                'fecha' => $fecha,
                'recolectados' => (int) ($fila->recolectados ?? 0),
                'rotos' => (int) ($fila->rotos ?? 0),
                'aprovechables' => (int) ($fila->aprovechables ?? 0),
            ];
        });

        $ultimos = fn (int $n) => $dias->slice(-$n)->values();
        $rango = $ultimos($periodo->dias);
        $recolectadosRango = (int) $rango->sum('recolectados');
        $rotosRango = (int) $rango->sum('rotos');

        return [
            'hoy' => $this->totales($dias->last()),
            'rango' => [
                'dias' => $periodo->dias,
                'recolectados' => $recolectadosRango,
                'rotos' => $rotosRango,
                'aprovechables' => (int) $rango->sum('aprovechables'),
                'porcentaje_rotura' => $recolectadosRango > 0
                    ? round(($rotosRango / $recolectadosRango) * 100, 1)
                    : 0,
            ],
            'serie' => $rango->map(fn (array $d) => [
                'fecha' => $d['fecha'],
                'recolectados' => $d['recolectados'],
                'aprovechables' => $d['aprovechables'],
                'rotos' => $d['rotos'],
            ])->all(),
            'ultimos_7' => $this->resumen($ultimos(7)),
            'ultimos_30' => $this->resumen($dias),
        ];
    }

    private function totales(array $dia): array
    {
        return [
            'recolectados' => $dia['recolectados'],
            'rotos' => $dia['rotos'],
            'aprovechables' => $dia['aprovechables'],
        ];
    }

    private function resumen(Collection $dias): array
    {
        return [
            'aprovechables' => (int) $dias->sum('aprovechables'),
            'rotos' => (int) $dias->sum('rotos'),
        ];
    }
}
