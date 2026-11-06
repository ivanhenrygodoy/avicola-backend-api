<?php

namespace App\Services\Dashboard\Indicadores;

use App\Models\Ave;
use App\Models\Casita;
use App\Models\Division;
use App\Services\Dashboard\Contracts\IndicadorDashboard;
use App\Services\Dashboard\PeriodoDashboard;
use Illuminate\Support\Facades\DB;

/**
 * Ocupación de aves activas por casita y división (conteo, sin porcentaje:
 * las divisiones no tienen capacidad definida).
 */
class OcupacionResumen implements IndicadorDashboard
{
    public function clave(): string
    {
        return 'ocupacion';
    }

    public function calcular(PeriodoDashboard $periodo): array
    {
        // Una sola consulta agrupada: (división, especie) → total.
        $conteos = Ave::activa()
            ->join('ctl_especie', 'ctl_especie.id', '=', 'mnt_aves.id_especie')
            ->whereNotNull('mnt_aves.id_division')
            ->groupBy('mnt_aves.id_division', 'ctl_especie.nombre')
            ->orderBy('ctl_especie.nombre')
            ->get(['mnt_aves.id_division', 'ctl_especie.nombre as especie', DB::raw('count(*) as total')])
            ->groupBy('id_division');

        $divisiones = Division::orderBy('id')->get()->groupBy('id_casita');

        return Casita::orderBy('id')
            ->get()
            ->map(function (Casita $casita) use ($divisiones, $conteos) {
                $filas = ($divisiones[$casita->id] ?? collect())->map(function (Division $division) use ($conteos) {
                    $porEspecie = $conteos[$division->id] ?? collect();

                    return [
                        'id_division' => $division->id,
                        'division' => $division->nombre,
                        'activa' => (bool) $division->activa,
                        'total_aves' => (int) $porEspecie->sum('total'),
                        'especies' => $porEspecie->pluck('especie')->values()->all(),
                    ];
                })->values();

                return [
                    'id_casita' => $casita->id,
                    'casita' => $casita->nombre,
                    'total_aves' => (int) $filas->sum('total_aves'),
                    'divisiones' => $filas->all(),
                ];
            })
            ->all();
    }
}
