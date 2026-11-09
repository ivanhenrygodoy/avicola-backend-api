<?php

namespace App\Providers;

use App\Services\Asistente\AsistenteToolRegistry;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use App\Services\Asistente\Tools\ConsultarGastosPeriodoTool;
use App\Services\Asistente\Tools\ConsultarInventarioAvesTool;
use App\Services\Asistente\Tools\ConsultarProduccionHuevosTool;
use App\Services\Asistente\Tools\ConsultarRacionesAlimentoTool;
use App\Services\Asistente\Tools\ConsultarUbicacionAveTool;
use App\Services\Dashboard\Contracts\IndicadorDashboard;
use App\Services\Dashboard\DashboardResumenService;
use App\Services\Dashboard\Indicadores\AlertasResumen;
use App\Services\Dashboard\Indicadores\AlimentacionResumen;
use App\Services\Dashboard\Indicadores\AvesResumen;
use App\Services\Dashboard\Indicadores\GastosResumen;
use App\Services\Dashboard\Indicadores\OcupacionResumen;
use App\Services\Dashboard\Indicadores\ProduccionHuevosResumen;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Indicadores del dashboard (OCP: agregar uno = agregar una clase aquí).
     *
     * @var array<int, class-string<IndicadorDashboard>>
     */
    private const INDICADORES_DASHBOARD = [
        AvesResumen::class,
        OcupacionResumen::class,
        ProduccionHuevosResumen::class,
        AlimentacionResumen::class,
        GastosResumen::class,
        AlertasResumen::class,
    ];

    /**
     * Herramientas autorizadas para el Asistente de IA (OCP: agregar una = agregar una clase aquí).
     *
     * @var array<int, class-string<AsistenteToolInterface>>
     */
    private const ASISTENTE_TOOLS = [
        ConsultarInventarioAvesTool::class,
        ConsultarProduccionHuevosTool::class,
        ConsultarGastosPeriodoTool::class,
        ConsultarUbicacionAveTool::class,
        ConsultarRacionesAlimentoTool::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->tag(self::INDICADORES_DASHBOARD, 'dashboard.indicadores');

        $this->app->when(DashboardResumenService::class)
            ->needs('$indicadores')
            ->giveTagged('dashboard.indicadores');

        $this->app->tag(self::ASISTENTE_TOOLS, 'asistente.tools');

        $this->app->when(AsistenteToolRegistry::class)
            ->needs('$herramientas')
            ->giveTagged('asistente.tools');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
