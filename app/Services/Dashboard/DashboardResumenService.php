<?php

namespace App\Services\Dashboard;

use App\Services\Dashboard\Contracts\IndicadorDashboard;

class DashboardResumenService
{
    /**
     * @param  iterable<IndicadorDashboard>  $indicadores
     */
    public function __construct(private readonly iterable $indicadores) {}

    public function resumen(int $dias = 7): array
    {
        $periodo = PeriodoDashboard::desde($dias);
        $resumen = [];

        foreach ($this->indicadores as $indicador) {
            $resumen[$indicador->clave()] = $indicador->calcular($periodo);
        }

        return $resumen;
    }
}
