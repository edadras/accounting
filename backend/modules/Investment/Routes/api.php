<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Investment\Http\Controllers\InvestmentController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('investments', [InvestmentController::class, 'index']);
        Route::post('investments', [InvestmentController::class, 'store']);
        Route::get('investments/{id}', [InvestmentController::class, 'show']);
        Route::post('investments/{id}/trades', [InvestmentController::class, 'trade']);
        Route::get('investments/{id}/performance', [InvestmentController::class, 'performance']);
    });
