<?php

namespace App\Services\Asistente\Benchmark;

use App\Models\FuncionLogAgent;
use App\Models\FuncionLogAgentTool;
use App\Services\Asistente\PromptComposer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas de solo lectura del módulo Benchmark AviaCore: estadísticas, listado filtrado y detalle.
 * Nunca expone datos del usuario (la bitácora no los guarda). Los días se agrupan en la zona horaria de la app.
 */
class BenchmarkConsultaService
{
    private const RESULTADOS = [
        FuncionLogAgent::RESULTADO_EXITOSO,
        FuncionLogAgent::RESULTADO_FAST_PATH,
        FuncionLogAgent::RESULTADO_BLOQUEADO,
        FuncionLogAgent::RESULTADO_ERROR,
    ];

    private const TOP = 8;

    /**
     * @return array<string, mixed>
     */
    public function estadisticas(int $dias): array
    {
        $zona = $this->zona();
        $hasta = CarbonImmutable::now($zona)->startOfDay();
        $desde = $hasta->subDays($dias - 1);
        $inicioUtc = $desde->startOfDay()->utc();
        $finUtc = $hasta->endOfDay()->utc();

        $logs = FuncionLogAgent::query()
            ->whereBetween('created_at', [$inicioUtc, $finUtc])
            ->get(['id', 'resultado', 'tokens_prompt', 'tokens_respuesta', 'tokens_total', 'duracion_ms', 'guards_nombres', 'created_at']);

        // Serie con todos los días del rango (los días sin datos quedan en 0).
        $serie = [];
        for ($d = $desde; $d->lessThanOrEqualTo($hasta); $d = $d->addDay()) {
            $serie[$d->format('Y-m-d')] = [
                'fecha' => $d->format('Y-m-d'),
                'exitosas' => 0,
                'bloqueadas' => 0,
                'errores' => 0,
                'tokens_prompt' => 0,
                'tokens_respuesta' => 0,
            ];
        }

        $porResultado = array_fill_keys(self::RESULTADOS, 0);
        $guards = [];

        foreach ($logs as $log) {
            $porResultado[$log->resultado] = ($porResultado[$log->resultado] ?? 0) + 1;

            $dia = $log->created_at->setTimezone($zona)->format('Y-m-d');
            if (isset($serie[$dia])) {
                $clave = match ($log->resultado) {
                    FuncionLogAgent::RESULTADO_BLOQUEADO => 'bloqueadas',
                    FuncionLogAgent::RESULTADO_ERROR => 'errores',
                    default => 'exitosas',
                };
                $serie[$dia][$clave]++;
                $serie[$dia]['tokens_prompt'] += $log->tokens_prompt;
                $serie[$dia]['tokens_respuesta'] += $log->tokens_respuesta;
            }

            foreach (array_filter(explode('|', (string) $log->guards_nombres)) as $guard) {
                $guards[$guard] = ($guards[$guard] ?? 0) + 1;
            }
        }

        $total = $logs->count();
        $exitosas = $porResultado[FuncionLogAgent::RESULTADO_EXITOSO] + $porResultado[FuncionLogAgent::RESULTADO_FAST_PATH];
        $tokens = (int) $logs->sum('tokens_total');

        return [
            'rango' => ['desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d')],
            'totales' => [
                'interacciones' => $total,
                'exitosas' => $exitosas,
                'fast_path' => $porResultado[FuncionLogAgent::RESULTADO_FAST_PATH],
                'bloqueadas' => $porResultado[FuncionLogAgent::RESULTADO_BLOQUEADO],
                'errores' => $porResultado[FuncionLogAgent::RESULTADO_ERROR],
                'tasa_exito' => $total > 0 ? round($exitosas / $total * 100, 1) : 0,
                'tokens_total' => $tokens,
                'tokens_promedio' => $total > 0 ? (int) round($tokens / $total) : 0,
                'latencia_promedio_ms' => $total > 0 ? (int) round($logs->avg('duracion_ms')) : 0,
            ],
            'serie' => array_values($serie),
            'por_resultado' => array_map(
                fn (string $resultado) => ['resultado' => $resultado, 'total' => $porResultado[$resultado]],
                self::RESULTADOS
            ),
            'top_guards' => $this->ranking($guards, 'guard'),
            'top_tools' => $this->topTools($inicioUtc, $finUtc),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function logs(array $filtros, int $porPagina): LengthAwarePaginator
    {
        return $this->consulta($filtros)
            ->with('tools:id,id_funcion_log_agent,orden,herramienta')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    /**
     * Totales del listado bajo el mismo filtro (exitosos incluye fast_path).
     *
     * @param  array<string, mixed>  $filtros
     * @return array{total: int, exitosos: int, bloqueados: int, errores: int}
     */
    public function resumenDelFiltro(array $filtros): array
    {
        $porResultado = $this->consulta($filtros)
            ->reorder()
            ->selectRaw('resultado, COUNT(*) as total')
            ->groupBy('resultado')
            ->pluck('total', 'resultado');

        $cuenta = fn (string $r) => (int) ($porResultado[$r] ?? 0);

        return [
            'total' => (int) $porResultado->sum(),
            'exitosos' => $cuenta(FuncionLogAgent::RESULTADO_EXITOSO) + $cuenta(FuncionLogAgent::RESULTADO_FAST_PATH),
            'bloqueados' => $cuenta(FuncionLogAgent::RESULTADO_BLOQUEADO),
            'errores' => $cuenta(FuncionLogAgent::RESULTADO_ERROR),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resumenLog(FuncionLogAgent $log): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at->setTimezone($this->zona())->format('d-m-Y H:i'),
            'mensaje_usuario' => $log->mensaje_usuario,
            'resultado' => $log->resultado,
            'tokens_total' => $log->tokens_total,
            'duracion_ms' => $log->duracion_ms,
            'guards' => array_values(array_filter(explode('|', (string) $log->guards_nombres))),
            'herramientas' => $log->tools->pluck('herramienta')->unique()->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detalle(FuncionLogAgent $log): array
    {
        $log->loadMissing('tools');
        $vigente = FuncionLogAgentService::versionPromptVigente();

        return [
            'id' => $log->id,
            'created_at' => $log->created_at->setTimezone($this->zona())->format('d-m-Y H:i:s'),
            'uuid_conversacion' => $log->uuid_conversacion,
            'mensaje_usuario' => $log->mensaje_usuario,
            'turnos_historial' => $log->turnos_historial,
            'prompt' => [
                'version' => $log->prompt_version,
                'texto' => PromptComposer::componer(),
                'version_vigente' => $log->prompt_version === $vigente,
            ],
            'respuesta_agente' => $log->respuesta_agente,
            'respuesta_original_llm' => $log->respuesta_original_llm,
            'resultado' => $log->resultado,
            'guards_aplicados' => $log->guards_aplicados ?? [],
            'error_mensaje' => $log->error_mensaje,
            'tokens' => [
                'prompt' => $log->tokens_prompt,
                'respuesta' => $log->tokens_respuesta,
                'total' => $log->tokens_total,
            ],
            'iteraciones' => $log->iteraciones,
            'duracion_ms' => $log->duracion_ms,
            'modelo' => $log->modelo,
            'tools' => $log->tools->map(fn (FuncionLogAgentTool $t) => [
                'orden' => $t->orden,
                'herramienta' => $t->herramienta,
                'argumentos' => $t->argumentos ?? [],
                'tablas_consultadas' => $t->tablas_consultadas ?? [],
                'resumen_resultado' => $t->resumen_resultado,
                'exito' => $t->exito,
                'duracion_ms' => $t->duracion_ms,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return Builder<FuncionLogAgent>
     */
    private function consulta(array $filtros): Builder
    {
        $query = FuncionLogAgent::query();
        $zona = $this->zona();

        if (! empty($filtros['resultado'])) {
            $query->where('resultado', $filtros['resultado']);
        }

        if (! empty($filtros['fecha_inicio'])) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filtros['fecha_inicio'], $zona)->startOfDay()->utc());
        }

        if (! empty($filtros['fecha_fin'])) {
            $query->where('created_at', '<=', CarbonImmutable::parse($filtros['fecha_fin'], $zona)->endOfDay()->utc());
        }

        if (! empty($filtros['guard'])) {
            $query->whereRaw("guards_nombres LIKE ? ESCAPE '!'", ['%|'.$this->escaparLike($filtros['guard']).'|%']);
        }

        if (! empty($filtros['herramienta'])) {
            $herramienta = $filtros['herramienta'];
            $query->whereHas('tools', fn (Builder $q) => $q->where('herramienta', $herramienta));
        }

        if (! empty($filtros['search'])) {
            $patron = '%'.$this->escaparLike(mb_strtolower(trim($filtros['search']))).'%';
            $query->where(function (Builder $q) use ($patron) {
                $q->whereRaw("LOWER(mensaje_usuario) LIKE ? ESCAPE '!'", [$patron])
                    ->orWhereRaw("LOWER(respuesta_agente) LIKE ? ESCAPE '!'", [$patron]);
            });
        }

        return $query;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topTools(CarbonImmutable $inicioUtc, CarbonImmutable $finUtc): array
    {
        return FuncionLogAgentTool::query()
            ->join('mnt_funcion_log_agent as l', 'l.id', '=', 'mnt_funcion_log_agent_tools.id_funcion_log_agent')
            ->whereBetween('l.created_at', [$inicioUtc, $finUtc])
            ->selectRaw('mnt_funcion_log_agent_tools.herramienta as herramienta, COUNT(*) as total')
            ->groupBy('mnt_funcion_log_agent_tools.herramienta')
            ->orderByDesc('total')
            ->orderBy('herramienta')
            ->limit(self::TOP)
            ->get()
            ->map(fn ($fila) => ['herramienta' => $fila->herramienta, 'total' => (int) $fila->total])
            ->all();
    }

    /**
     * @param  array<string, int>  $conteos
     * @return array<int, array<string, mixed>>
     */
    private function ranking(array $conteos, string $clave): array
    {
        arsort($conteos);

        $filas = [];
        foreach (array_slice($conteos, 0, self::TOP, true) as $nombre => $total) {
            $filas[] = [$clave => $nombre, 'total' => $total];
        }

        return $filas;
    }

    /**
     * Los comodines de LIKE (% y _) se tratan como texto literal. Se escapa con "!" (cláusula ESCAPE '!')
     * y no con barra invertida: el parser de PDO-pgsql confunde "ESCAPE '\'" y falla con "Invalid parameter number".
     */
    private function escaparLike(string $texto): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $texto);
    }

    private function zona(): string
    {
        return (string) config('avicola.timezone', 'America/El_Salvador');
    }
}
