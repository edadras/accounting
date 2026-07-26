<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Budget\Http\Controllers\BudgetController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('budgets', [BudgetController::class, 'index']);
        Route::post('budgets', [BudgetController::class, 'store']);

        // Before budgets/{id}, or "status" is swallowed as a budget id.
        Route::get('budgets/status', [BudgetController::class, 'status']);

        Route::get('budgets/{id}', [BudgetController::class, 'show']);
        Route::patch('budgets/{id}', [BudgetController::class, 'update']);
        Route::delete('budgets/{id}', [BudgetController::class, 'destroy']);
    });
