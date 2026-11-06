<?php

namespace App\Services\Dashboard\Indicadores;

use App\Models\Ave;
use App\Services\Dashboard\Contracts\IndicadorDashboard;
use App\Services\Dashboard\PeriodoDashboard;
use Illuminate\Support\Facades\DB;

/**
 * Población de aves activas, clasificada por especie y por sexo.
 */
class AvesResumen implements IndicadorDashboard
{
    public function clave(): string
    {
        return 'aves';
    }

    public function calcular(PeriodoDashboard $periodo): array
    {
        $porEspecie = $this->agrupar('ctl_especie', 'id_especie', 'especie');
        $porSexo = $this->agrupar('ctl_sexo', 'id_sexo', 'sexo');

        return [
            'total' => Ave::activa()->count(),
            'por_especie' => $porEspecie,
            'por_sexo' => $porSexo,
        ];
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function agrupar(string $catalogo, string $fk, string $alias): array
    {
        return Ave::activa()
            ->join($catalogo, "{$catalogo}.id", '=', "mnt_aves.{$fk}")
            ->groupBy("{$catalogo}.id", "{$catalogo}.nombre")
            ->orderByDesc(DB::raw('count(*)'))
            ->orderBy("{$catalogo}.nombre")
            ->get(["{$catalogo}.nombre as {$alias}", DB::raw('count(*) as total')])
            ->map(fn ($fila) => [$alias => $fila->{$alias}, 'total' => (int) $fila->total])
            ->all();
    }
}
