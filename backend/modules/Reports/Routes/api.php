<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Reports\Http\Controllers\ReportController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('reports', [ReportController::class, 'index']);
        Route::get('reports/{type}', [ReportController::class, 'show']);
    });
