<?php

namespace App\Services\Dashboard;

use App\Models\Gasto;
use App\Models\MovimientoAve;
use App\Models\ObservacionAve;
use App\Models\RegistroHuevo;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Feed de actividad reciente: traslados, postura, gastos y observaciones
 * mezclados, del más reciente al más antiguo según su momento de registro.
 *
 * Solo se expone el historial de los últimos MAX_EVENTOS eventos: acota el
 * costo de la consulta (cada fuente aporta como máximo MAX_EVENTOS filas) y
 * mantiene corto el paginador.
 */
class ActividadRecienteService
{
    public const MAX_EVENTOS = 50;

    public function paginar(int $pagina, int $porPagina): LengthAwarePaginator
    {
        $eventos = $this->traslados()
            ->concat($this->huevos())
            ->concat($this->gastos())
            ->concat($this->observaciones())
            ->sortByDesc('momento')
            ->take(self::MAX_EVENTOS)
            ->values();

        return new LengthAwarePaginator(
            $eventos->forPage($pagina, $porPagina)->map(fn (array $e) => [
                'tipo' => $e['tipo'],
                'fecha' => $e['momento']->setTimezone(config('avicola.timezone'))->format('Y-m-d H:i'),
                'descripcion' => $e['descripcion'],
            ])->values(),
            $eventos->count(),
            $porPagina,
            $pagina,
        );
    }

    /** @return Collection<int, array{tipo:string, momento:CarbonInterface, descripcion:string}> */
    private function traslados(): Collection
    {
        return MovimientoAve::with(['ave', 'divisionOrigen', 'divisionDestino'])
            ->latest('created_at')->limit(self::MAX_EVENTOS)->get()
            ->map(fn (MovimientoAve $m) => $this->evento('traslado', $m->created_at, sprintf(
                'Traslado de %s: %s → %s.',
                $m->ave?->nombre ?: $m->ave?->codigo ?: 'ave',
                $m->divisionOrigen?->nombre ?? 'sin origen',
                $m->divisionDestino?->nombre ?? 'baja',
            )));
    }

    private function huevos(): Collection
    {
        return RegistroHuevo::with('division')
            ->latest('created_at')->limit(self::MAX_EVENTOS)->get()
            ->map(fn (RegistroHuevo $r) => $this->evento('huevos', $r->created_at, sprintf(
                'Recolección en %s: %d huevos (%d rotos).',
                $r->division?->nombre ?? 'corral',
                $r->cantidad_recolectada,
                $r->cantidad_rotos,
            )));
    }

    private function gastos(): Collection
    {
        return Gasto::with('categoria')
            ->latest('created_at')->limit(self::MAX_EVENTOS)->get()
            ->map(fn (Gasto $g) => $this->evento('gasto', $g->created_at, sprintf(
                'Gasto de $%s en %s: %s.',
                number_format((float) $g->monto, 2),
                $g->categoria?->nombre ?? 'sin categoría',
                $g->concepto,
            )));
    }

    private function observaciones(): Collection
    {
        return ObservacionAve::with('ave')
            ->latest('created_at')->limit(self::MAX_EVENTOS)->get()
            ->map(fn (ObservacionAve $o) => $this->evento('observacion', $o->created_at, sprintf(
                '%s de %s: %s.',
                $o->tipo,
                $o->ave?->nombre ?: $o->ave?->codigo ?: 'ave',
                $o->titulo,
            )));
    }

    private function evento(string $tipo, CarbonInterface $momento, string $descripcion): array
    {
        return ['tipo' => $tipo, 'momento' => $momento, 'descripcion' => $descripcion];
    }
}
