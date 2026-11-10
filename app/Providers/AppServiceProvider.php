<?php

namespace App\Providers;

use App\Services\Asistente\AsistenteToolRegistry;
use App\Services\Asistente\Contracts\AsistenteToolInterface;
use App\Services\Asistente\Guardrails\Bucle\ToolLoopGuard;
use App\Services\Asistente\Guardrails\Contracts\GuardrailInterface;
use App\Services\Asistente\Guardrails\Entrada\ContextTamperingGuard;
use App\Services\Asistente\Guardrails\Entrada\MathematicalOffloadingGuard;
use App\Services\Asistente\Guardrails\Entrada\PersonaDriftGuard;
use App\Services\Asistente\Guardrails\Entrada\ProfanityAndToxicityGuard;
use App\Services\Asistente\Guardrails\Entrada\PromptInjectionGuard;
use App\Services\Asistente\Guardrails\Entrada\SaludoFastPathGuard;
use App\Services\Asistente\Guardrails\Entrada\ScopeEnforcementGuard;
use App\Services\Asistente\Guardrails\GuardrailPipeline;
use App\Services\Asistente\Guardrails\Salida\DateFormatGuard;
use App\Services\Asistente\Guardrails\Salida\LengthAndBrevityGuard;
use App\Services\Asistente\Guardrails\Salida\SourceAttributionGuard;
use App\Services\Asistente\Guardrails\Salida\SystemLeakageGuard;
use App\Services\Asistente\Guardrails\Tool\ToolArgumentValidationGuard;
use App\Services\Asistente\Tools\ConsultarGastosPeriodoTool;
use App\Services\Asistente\Tools\ConsultarInventarioAvesTool;
use App\Services\Asistente\Tools\ConsultarManualesTecnicosRAGTool;
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
        ConsultarManualesTecnicosRAGTool::class,
    ];

    /**
     * Guardrails del Asistente de IA, en orden de ejecución (OCP: agregar uno = agregar una clase aquí).
     *
     * @var array<int, class-string<GuardrailInterface>>
     */
    private const ASISTENTE_GUARDRAILS = [
        // Entrada (el primero que bloquea corta el turno antes de llamar al LLM)
        SaludoFastPathGuard::class,
        PromptInjectionGuard::class,
        ProfanityAndToxicityGuard::class,
        PersonaDriftGuard::class,
        ContextTamperingGuard::class,
        MathematicalOffloadingGuard::class,
        ScopeEnforcementGuard::class,
        // Tool (validan los argumentos del modelo antes de ejecutar la herramienta) y bucle (tope de iteraciones)
        ToolArgumentValidationGuard::class,
        ToolLoopGuard::class,
        // Salida (se encadenan sobre la respuesta del modelo)
        SystemLeakageGuard::class,
        DateFormatGuard::class,
        SourceAttributionGuard::class,
        LengthAndBrevityGuard::class,
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

        $this->app->tag(self::ASISTENTE_GUARDRAILS, 'asistente.guardrails');

        $this->app->when(GuardrailPipeline::class)
            ->needs('$guards')
            ->giveTagged('asistente.guardrails');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
