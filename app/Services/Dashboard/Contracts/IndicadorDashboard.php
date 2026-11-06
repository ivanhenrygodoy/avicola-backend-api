<?php

namespace App\Services\Dashboard\Contracts;

use App\Services\Dashboard\PeriodoDashboard;

interface IndicadorDashboard
{
    /**
     * Clave bajo la cual el resultado aparece en la respuesta del resumen.
     */
    public function clave(): string;

    /**
     * Calcula el indicador para el periodo dado.
     */
    public function calcular(PeriodoDashboard $periodo): array;
}
