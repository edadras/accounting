<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Travel\Http\Controllers\SettlementController;
use Modules\Travel\Http\Controllers\SplitExpenseController;
use Modules\Travel\Http\Controllers\TripController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('trips', [TripController::class, 'index']);
        Route::post('trips', [TripController::class, 'store']);
        Route::get('trips/{trip}', [TripController::class, 'show']);
        Route::post('trips/{trip}/members', [TripController::class, 'storeMember']);

        Route::get('trips/{trip}/expenses', [SplitExpenseController::class, 'index']);
        Route::post('trips/{trip}/expenses', [SplitExpenseController::class, 'store']);
        Route::delete('trips/{trip}/expenses/{expense}', [SplitExpenseController::class, 'destroy']);

        Route::get('trips/{trip}/settlement/preview', [SettlementController::class, 'preview']);
        Route::post('trips/{trip}/settlement', [SettlementController::class, 'settle']);
    });
