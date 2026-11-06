<?php

namespace App\Services\Dashboard\Indicadores;

use App\Models\Gasto;
use App\Services\Dashboard\Contracts\IndicadorDashboard;
use App\Services\Dashboard\PeriodoDashboard;
use Illuminate\Support\Facades\DB;

/**
 * Gastos del mes actual con desglose por categoría (mayor a menor).
 */
class GastosResumen implements IndicadorDashboard
{
    public function clave(): string
    {
        return 'gastos';
    }

    public function calcular(PeriodoDashboard $periodo): array
    {
        $porCategoria = Gasto::query()
            ->join('ctl_categorias_gastos', 'ctl_categorias_gastos.id', '=', 'mnt_gastos.id_categoria_gasto')
            ->whereBetween('mnt_gastos.fecha', [$periodo->inicioMes->toDateString(), $periodo->finMes->toDateString()])
            ->groupBy('ctl_categorias_gastos.id', 'ctl_categorias_gastos.nombre')
            ->orderByDesc(DB::raw('sum(mnt_gastos.monto)'))
            ->orderBy('ctl_categorias_gastos.nombre')
            ->get(['ctl_categorias_gastos.nombre as categoria', DB::raw('sum(mnt_gastos.monto) as total')])
            ->map(fn ($fila) => [
                'categoria' => $fila->categoria,
                'total' => round((float) $fila->total, 2),
            ]);

        return [
            'total_mes' => round((float) $porCategoria->sum('total'), 2),
            'por_categoria' => $porCategoria->values()->all(),
        ];
    }
}
