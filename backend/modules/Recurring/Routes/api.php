<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Recurring\Http\Controllers\RecurringRuleController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('recurring-rules', [RecurringRuleController::class, 'index']);
        Route::post('recurring-rules', [RecurringRuleController::class, 'store']);
        Route::patch('recurring-rules/{rule}', [RecurringRuleController::class, 'update']);
        Route::delete('recurring-rules/{rule}', [RecurringRuleController::class, 'destroy']);
    });
