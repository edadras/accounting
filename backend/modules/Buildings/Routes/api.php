<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Buildings\Http\Controllers\BuildingChargeController;
use Modules\Buildings\Http\Controllers\BuildingController;
use Modules\Buildings\Http\Controllers\BuildingExpenseController;
use Modules\Buildings\Http\Controllers\BuildingReportController;
use Modules\Buildings\Http\Controllers\BuildingUnitController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('buildings', [BuildingController::class, 'index']);
        Route::post('buildings', [BuildingController::class, 'store']);
        Route::get('buildings/{building}', [BuildingController::class, 'show']);
        Route::patch('buildings/{building}', [BuildingController::class, 'update']);

        Route::get('buildings/{building}/units', [BuildingUnitController::class, 'index']);
        Route::post('buildings/{building}/units', [BuildingUnitController::class, 'store']);
        Route::patch('buildings/{building}/units/{unit}', [BuildingUnitController::class, 'update']);
        Route::delete('buildings/{building}/units/{unit}', [BuildingUnitController::class, 'destroy']);

        Route::get('buildings/{building}/charges', [BuildingChargeController::class, 'index']);
        Route::post('buildings/{building}/charges/issue', [BuildingChargeController::class, 'issue']);
        Route::post('building-charges/{charge}/pay', [BuildingChargeController::class, 'pay']);

        Route::get('buildings/{building}/expenses', [BuildingExpenseController::class, 'index']);
        Route::post('buildings/{building}/expenses', [BuildingExpenseController::class, 'store']);

        Route::get('buildings/{building}/reports/debtors', [BuildingReportController::class, 'debtors']);
        Route::get('buildings/{building}/reports/fund', [BuildingReportController::class, 'fund']);
    });
