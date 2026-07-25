<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Alerts\Http\Controllers\AlertController;
use Modules\Alerts\Http\Controllers\AlertPreferenceController;
use Modules\Alerts\Http\Controllers\AlertRuleController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('alerts', [AlertController::class, 'index']);

        // Before alerts/{id}: these are collections, not alert ids.
        Route::get('alerts/rules', [AlertRuleController::class, 'index']);
        Route::post('alerts/rules', [AlertRuleController::class, 'store']);
        Route::delete('alerts/rules/{id}', [AlertRuleController::class, 'destroy']);

        Route::get('alerts/preferences', [AlertPreferenceController::class, 'show']);
        Route::put('alerts/preferences', [AlertPreferenceController::class, 'update']);

        Route::post('alerts/{id}/read', [AlertController::class, 'read']);
    });
