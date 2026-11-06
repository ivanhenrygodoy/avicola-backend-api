<?php

namespace App\Services\Dashboard\Indicadores;

use App\Models\Ave;
use App\Models\RegistroAlimentacion;
use App\Models\RegistroHuevo;
use App\Services\Dashboard\Contracts\IndicadorDashboard;
use App\Services\Dashboard\PeriodoDashboard;

/**
 * Alertas operativas preventivas del día.
 */
class AlertasResumen implements IndicadorDashboard
{
    /** Porcentaje de rotura (ventana de 7 días) por encima del cual se alerta. */
    public const UMBRAL_ROTURA = 10.0;

    private const DIAS_ROTURA = 7;

    public function clave(): string
    {
        return 'alertas';
    }

    public function calcular(PeriodoDashboard $periodo): array
    {
        $hoy = $periodo->hoy->toDateString();
        $alertas = [];

        if (Ave::activa()->exists()) {
            if (! RegistroHuevo::whereDate('fecha', $hoy)->exists()) {
                $alertas[] = $this->alerta('sin_postura_hoy', 'Aún no se registra la recolección de huevos de hoy.');
            }

            if (! RegistroAlimentacion::whereDate('fecha', $hoy)->exists()) {
                $alertas[] = $this->alerta('sin_alimentacion_hoy', 'Aún no se registra la alimentación de hoy.');
            }
        }

        $rotura = $this->porcentajeRotura($periodo);
        if ($rotura > self::UMBRAL_ROTURA) {
            $alertas[] = $this->alerta(
                'rotura_alta',
                sprintf('La rotura de huevos en los últimos %d días es de %s%%, por encima del %s%% esperado.', self::DIAS_ROTURA, $rotura, self::UMBRAL_ROTURA),
            );
        }

        return $alertas;
    }

    private function porcentajeRotura(PeriodoDashboard $periodo): float
    {
        $desde = $periodo->hoy->subDays(self::DIAS_ROTURA - 1)->toDateString();

        $fila = RegistroHuevo::query()
            ->whereBetween('fecha', [$desde, $periodo->hoy->toDateString()])
            ->selectRaw('coalesce(sum(cantidad_recolectada), 0) as recolectados, coalesce(sum(cantidad_rotos), 0) as rotos')
            ->first();

        $recolectados = (int) $fila->recolectados;

        return $recolectados > 0 ? round(((int) $fila->rotos / $recolectados) * 100, 1) : 0.0;
    }

    private function alerta(string $codigo, string $mensaje): array
    {
        return ['codigo' => $codigo, 'nivel' => 'warning', 'mensaje' => $mensaje];
    }
}
