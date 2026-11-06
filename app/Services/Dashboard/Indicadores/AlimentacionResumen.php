<?php

namespace App\Services\Dashboard\Indicadores;

use App\Models\RegistroAlimentacion;
use App\Services\Dashboard\Contracts\IndicadorDashboard;
use App\Services\Dashboard\PeriodoDashboard;

/**
 * Alimento suministrado en kilos (hoy y mes actual).
 * Solo se suman registros en kg: otras unidades no son convertibles.
 */
class AlimentacionResumen implements IndicadorDashboard
{
    public function clave(): string
    {
        return 'alimentacion';
    }

    public function calcular(PeriodoDashboard $periodo): array
    {
        $fila = RegistroAlimentacion::query()
            ->where('unidad_medida', 'kg')
            ->whereBetween('fecha', [$periodo->inicioMes->toDateString(), $periodo->finMes->toDateString()])
            ->selectRaw('coalesce(sum(cantidad), 0) as kilos_mes')
            ->selectRaw('coalesce(sum(case when fecha = ? then cantidad else 0 end), 0) as kilos_hoy', [$periodo->hoy->toDateString()])
            ->first();

        return [
            'kilos_mes' => round((float) $fila->kilos_mes, 2),
            'kilos_hoy' => round((float) $fila->kilos_hoy, 2),
        ];
    }
}
