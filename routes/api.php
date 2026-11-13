<?php

use App\Http\Controllers\Api\V1\AlimentacionController;
use App\Http\Controllers\Api\V1\AsistenteBenchmarkController;
use App\Http\Controllers\Api\V1\AsistenteController;
use App\Http\Controllers\Api\V1\AsistenteConversacionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AveController;
use App\Http\Controllers\Api\V1\CasitaController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DepuracionLogsAgenteController;
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\GastoController;
use App\Http\Controllers\Api\V1\GranjaController;
use App\Http\Controllers\Api\V1\HuevoController;
use App\Http\Controllers\Api\V1\ObservacionAveController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\PermisoController;
use App\Http\Controllers\Api\V1\PesoAveController;
use App\Http\Controllers\Api\V1\UsuarioController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Rutas públicas de autenticación
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        // Rutas protegidas de autenticación
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('password', [PasswordController::class, 'cambiar']);
            Route::post('password/mantener', [PasswordController::class, 'mantener']);
        });
    });

    // Rutas protegidas del negocio
    Route::middleware('auth:sanctum')->group(function () {
        // Catálogos compartidos: los usan varios módulos, así que solo exigen autenticación
        // (no leen datos del Benchmark). Se declaran antes de los apiResource para no chocar con {id}.
        Route::get('instalaciones/tipos', [CasitaController::class, 'tipos']);
        Route::get('casitas/tipos', [CasitaController::class, 'tipos']);
        Route::get('catalogos/tipos-instalacion', [CasitaController::class, 'tipos']);
        Route::get('aves/catalogos', [AveController::class, 'catalogos']);
        Route::get('aves/especies', [AveController::class, 'especies']);
        Route::get('aves/sexos', [AveController::class, 'sexos']);
        Route::get('aves/propositos', [AveController::class, 'propositos']);
        Route::get('catalogos/especies', [AveController::class, 'especies']);
        Route::get('catalogos/sexos', [AveController::class, 'sexos']);
        Route::get('catalogos/propositos', [AveController::class, 'propositos']);
        Route::get('catalogos/tipos-alimento', [AlimentacionController::class, 'tipos']);
        Route::get('alimentos/tipos', [AlimentacionController::class, 'tipos']);
        Route::get('catalogos/categorias-gastos', [GastoController::class, 'categorias']);
        Route::get('gastos/categorias', [GastoController::class, 'categorias']);

        // Módulo 2: Instalaciones (Granjas, Casitas y Divisiones)
        Route::middleware('modulo:instalaciones')->group(function () {
            Route::get('granjas', [GranjaController::class, 'index']);
            Route::apiResource('casitas', CasitaController::class);
            Route::patch('casitas/{casita}/toggle-activa', [CasitaController::class, 'toggleActiva']);
            Route::apiResource('divisiones', DivisionController::class)->parameters(['divisiones' => 'division']);
            Route::patch('divisiones/{division}/toggle-activa', [DivisionController::class, 'toggleActiva']);
        });

        // Módulo 3: Inventario de Aves y Movimientos (+ seguimiento individual: pesos y bitácora)
        Route::middleware('modulo:aves')->group(function () {
            Route::apiResource('aves', AveController::class)->parameters(['aves' => 'ave']);
            Route::patch('aves/{ave}/reactivar', [AveController::class, 'reactivar']);
            Route::post('aves/{ave}/trasladar', [AveController::class, 'trasladar']);
            Route::get('aves/{ave}/movimientos', [AveController::class, 'movimientos']);

            Route::get('aves/{ave}/pesos', [PesoAveController::class, 'index']);
            Route::post('aves/{ave}/pesos', [PesoAveController::class, 'store']);
            Route::delete('pesos/{peso}', [PesoAveController::class, 'destroy']);

            Route::get('aves/{ave}/observaciones', [ObservacionAveController::class, 'index']);
            Route::post('aves/{ave}/observaciones', [ObservacionAveController::class, 'store']);
            Route::delete('observaciones/{observacion}', [ObservacionAveController::class, 'destroy']);
        });

        // Módulo 4: Alimentación y Producción de Huevos
        Route::middleware('modulo:produccion')->group(function () {
            Route::apiResource('alimentacion', AlimentacionController::class)->parameters(['alimentacion' => 'alimentacion']);
            Route::apiResource('huevos', HuevoController::class)->parameters(['huevos' => 'huevo']);
        });

        // Módulo 5: Control de Gastos y Finanzas Operativas
        Route::middleware('modulo:gastos')->group(function () {
            Route::apiResource('gastos', GastoController::class)->parameters(['gastos' => 'gasto']);
        });

        // Módulo 6: Dashboard Analítico
        Route::middleware('modulo:dashboard')->group(function () {
            Route::get('dashboard/resumen', [DashboardController::class, 'resumen']);
            Route::get('dashboard/actividad', [DashboardController::class, 'actividad']);
        });

        // Módulo 7: Asistente Virtual Inteligente (IA Function Calling)
        Route::middleware('modulo:asistente')->group(function () {
            Route::post('asistente/chat', [AsistenteController::class, 'chat']);

            // Historial de conversaciones del asistente (nada se borra: "eliminar" solo cambia el estado)
            Route::get('asistente/conversaciones', [AsistenteConversacionController::class, 'index']);
            Route::get('asistente/conversaciones/{uuid}', [AsistenteConversacionController::class, 'show']);
            Route::patch('asistente/conversaciones/{uuid}', [AsistenteConversacionController::class, 'update']);
            Route::delete('asistente/conversaciones/{uuid}', [AsistenteConversacionController::class, 'destroy']);
            // Nombre automático (una llamada corta a Gemini): con límite para que no se pueda abusar del proveedor
            Route::post('asistente/conversaciones/{uuid}/titulo', [AsistenteConversacionController::class, 'titulo'])
                ->middleware('throttle:10,1');
        });

        // Gestión de usuarios (solo superAdmin)
        Route::middleware('modulo:usuarios')->group(function () {
            Route::get('usuarios', [UsuarioController::class, 'index']);
            Route::post('usuarios', [UsuarioController::class, 'store']);
            Route::patch('usuarios/{usuario}', [UsuarioController::class, 'update']);
            Route::post('usuarios/{usuario}/reenviar-credenciales', [UsuarioController::class, 'reenviarCredenciales']);
        });

        // Gestión de permisos por rol (solo superAdmin)
        Route::middleware('modulo:permisos')->group(function () {
            Route::get('permisos', [PermisoController::class, 'index']);
            Route::put('permisos/{rol}', [PermisoController::class, 'update']);
        });

        // Benchmark AviaCore: auditoría de las interacciones del asistente
        Route::middleware('modulo:benchmark')->prefix('asistente/benchmark')->group(function () {
            Route::get('estadisticas', [AsistenteBenchmarkController::class, 'estadisticas']);
            Route::get('logs', [AsistenteBenchmarkController::class, 'logs']);
            Route::get('logs/{log}', [AsistenteBenchmarkController::class, 'show']);
            Route::get('depuracion', [DepuracionLogsAgenteController::class, 'vistaPrevia']);
            Route::delete('depuracion', [DepuracionLogsAgenteController::class, 'destroy']);
        });
    });
});
