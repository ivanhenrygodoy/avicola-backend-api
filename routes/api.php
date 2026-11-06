<?php

use App\Http\Controllers\Api\V1\AlimentacionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AveController;
use App\Http\Controllers\Api\V1\CasitaController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DivisionController;
use App\Http\Controllers\Api\V1\GastoController;
use App\Http\Controllers\Api\V1\HuevoController;
use App\Http\Controllers\Api\V1\ObservacionAveController;
use App\Http\Controllers\Api\V1\PesoAveController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Rutas públicas de autenticación
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login']);

        // Rutas protegidas de autenticación
        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // Rutas protegidas del negocio
    Route::middleware('auth:sanctum')->group(function () {
        // Módulo 2: Instalaciones (Casitas y Divisiones)
        Route::get('instalaciones/tipos', [CasitaController::class, 'tipos']);
        Route::get('casitas/tipos', [CasitaController::class, 'tipos']);
        Route::get('catalogos/tipos-instalacion', [CasitaController::class, 'tipos']);
        Route::apiResource('casitas', CasitaController::class);
        Route::patch('casitas/{casita}/toggle-activa', [CasitaController::class, 'toggleActiva']);
        Route::apiResource('divisiones', DivisionController::class);
        Route::patch('divisiones/{division}/toggle-activa', [DivisionController::class, 'toggleActiva']);

        // Módulo 3: Inventario de Aves y Movimientos
        Route::get('aves/catalogos', [AveController::class, 'catalogos']);
        Route::get('aves/especies', [AveController::class, 'especies']);
        Route::get('aves/sexos', [AveController::class, 'sexos']);
        Route::get('aves/propositos', [AveController::class, 'propositos']);
        Route::get('catalogos/especies', [AveController::class, 'especies']);
        Route::get('catalogos/sexos', [AveController::class, 'sexos']);
        Route::get('catalogos/propositos', [AveController::class, 'propositos']);
        Route::apiResource('aves', AveController::class)->parameters(['aves' => 'ave']);
        Route::patch('aves/{ave}/reactivar', [AveController::class, 'reactivar']);
        Route::post('aves/{ave}/trasladar', [AveController::class, 'trasladar']);
        Route::get('aves/{ave}/movimientos', [AveController::class, 'movimientos']);

        // Seguimiento individual de aves (Pesos y Bitácora)
        Route::get('aves/{ave}/pesos', [PesoAveController::class, 'index']);
        Route::post('aves/{ave}/pesos', [PesoAveController::class, 'store']);
        Route::delete('pesos/{peso}', [PesoAveController::class, 'destroy']);

        Route::get('aves/{ave}/observaciones', [ObservacionAveController::class, 'index']);
        Route::post('aves/{ave}/observaciones', [ObservacionAveController::class, 'store']);
        Route::delete('observaciones/{observacion}', [ObservacionAveController::class, 'destroy']);

        // Módulo 4: Alimentación y Producción de Huevos
        Route::get('catalogos/tipos-alimento', [AlimentacionController::class, 'tipos']);
        Route::get('alimentos/tipos', [AlimentacionController::class, 'tipos']);
        Route::apiResource('alimentacion', AlimentacionController::class)->parameters(['alimentacion' => 'alimentacion']);
        Route::apiResource('huevos', HuevoController::class)->parameters(['huevos' => 'huevo']);

        // Módulo 5: Control de Gastos y Finanzas Operativas
        Route::get('catalogos/categorias-gastos', [GastoController::class, 'categorias']);
        Route::get('gastos/categorias', [GastoController::class, 'categorias']);
        Route::apiResource('gastos', GastoController::class)->parameters(['gastos' => 'gasto']);

        // Módulo 6: Dashboard Analítico
        Route::get('dashboard/resumen', [DashboardController::class, 'resumen']);
        Route::get('dashboard/actividad', [DashboardController::class, 'actividad']);
    });
});
