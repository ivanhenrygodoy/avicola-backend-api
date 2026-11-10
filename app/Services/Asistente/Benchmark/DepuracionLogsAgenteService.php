<?php

namespace App\Services\Asistente\Benchmark;

use App\Models\FuncionLogAgent;
use App\Models\FuncionLogAgentTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Servicio encargado de la vista previa y depuración por lotes del histórico de turnos del asistente.
 *
 * Aplica una lista cerrada de meses permitidos (3, 6, 12) para proteger la confidencialidad
 * y la integridad de los últimos 3 meses. Borra en transacciones por lotes eliminando primero
 * las herramientas (mnt_funcion_log_agent_tools) para respetar la restricción foránea.
 */
class DepuracionLogsAgenteService
{
    /** Lista cerrada de meses permitidos para la depuración de logs. */
    public const MESES_PERMITIDOS = [3, 6, 12];

    /**
     * Calcula la fecha de corte y genera la vista previa de cuántos registros se eliminarían.
     * No escribe nada en la base de datos.
     *
     * @return array{meses: int, corte: string, turnos: int, tools: int, conservados: int}
     */
    public function vistaPrevia(int $meses): array
    {
        $this->validarMeses($meses);
        $corteUtc = $this->calcularCorteUtc($meses);

        $idsTurnos = FuncionLogAgent::query()
            ->where('created_at', '<', $corteUtc)
            ->pluck('id');

        $turnos = $idsTurnos->count();
        $tools = $turnos > 0
            ? FuncionLogAgentTool::query()->whereIn('id_funcion_log_agent', $idsTurnos)->count()
            : 0;

        $conservados = FuncionLogAgent::query()
            ->where('created_at', '>=', $corteUtc)
            ->count();

        return [
            'meses' => $meses,
            'corte' => $this->formatearCorteNegocio($meses),
            'turnos' => $turnos,
            'tools' => $tools,
            'conservados' => $conservados,
        ];
    }

    /**
     * Elimina por lotes las herramientas y turnos anteriores al corte.
     *
     * @return array{meses: int, corte: string, turnos: int, tools: int, conservados: int}
     */
    public function depurar(int $meses, int $tamanoLote = 500): array
    {
        $this->validarMeses($meses);
        $corteUtc = $this->calcularCorteUtc($meses);

        $totalTurnosEliminados = 0;
        $totalToolsEliminadas = 0;

        do {
            $loteIds = FuncionLogAgent::query()
                ->where('created_at', '<', $corteUtc)
                ->limit($tamanoLote)
                ->pluck('id');

            if ($loteIds->isEmpty()) {
                break;
            }

            $eliminadosEnLote = DB::transaction(function () use ($loteIds) {
                // 1. Eliminar herramientas hijas de los turnos del lote (restrictOnDelete)
                $toolsCount = FuncionLogAgentTool::query()
                    ->whereIn('id_funcion_log_agent', $loteIds)
                    ->delete();

                // 2. Eliminar turnos padres del lote
                $turnosCount = FuncionLogAgent::query()
                    ->whereIn('id', $loteIds)
                    ->delete();

                return ['turnos' => $turnosCount, 'tools' => $toolsCount];
            });

            $totalTurnosEliminados += $eliminadosEnLote['turnos'];
            $totalToolsEliminadas += $eliminadosEnLote['tools'];

        } while ($loteIds->count() >= $tamanoLote);

        $conservados = FuncionLogAgent::query()
            ->where('created_at', '>=', $corteUtc)
            ->count();

        return [
            'meses' => $meses,
            'corte' => $this->formatearCorteNegocio($meses),
            'turnos' => $totalTurnosEliminados,
            'tools' => $totalToolsEliminadas,
            'conservados' => $conservados,
        ];
    }

    private function validarMeses(int $meses): void
    {
        if (! in_array($meses, self::MESES_PERMITIDOS, true)) {
            throw new InvalidArgumentException(
                'El parámetro de depuración debe ser uno de los períodos permitidos: '.implode(', ', self::MESES_PERMITIDOS).' meses.'
            );
        }
    }

    private function calcularCorteNegocio(int $meses): CarbonImmutable
    {
        $zona = config('avicola.timezone', 'America/El_Salvador');

        return CarbonImmutable::now($zona)->subMonthsNoOverflow($meses);
    }

    private function calcularCorteUtc(int $meses): CarbonImmutable
    {
        return $this->calcularCorteNegocio($meses)->setTimezone('UTC');
    }

    private function formatearCorteNegocio(int $meses): string
    {
        return $this->calcularCorteNegocio($meses)->format('d-m-Y');
    }
}
