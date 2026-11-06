<?php

namespace App\Services\Dashboard;

use Carbon\CarbonImmutable;

/**
 * Value object con las referencias temporales del dashboard,
 * todas expresadas en la zona horaria de negocio.
 */
final class PeriodoDashboard
{
    private function __construct(
        public readonly CarbonImmutable $hoy,
        public readonly CarbonImmutable $inicioMes,
        public readonly CarbonImmutable $finMes,
        public readonly CarbonImmutable $inicioRango,
        public readonly int $dias,
    ) {}

    public static function desde(int $dias = 7): self
    {
        $hoy = CarbonImmutable::now(config('avicola.timezone'))->startOfDay();

        return new self(
            hoy: $hoy,
            inicioMes: $hoy->startOfMonth(),
            finMes: $hoy->endOfMonth()->startOfDay(),
            inicioRango: $hoy->subDays($dias - 1),
            dias: $dias,
        );
    }
}
