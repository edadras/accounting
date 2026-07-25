<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Reports\Http\Controllers\ReportController;
use Modules\Reports\Http\Controllers\ReportExportController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('reports', [ReportController::class, 'index']);

        // Before `reports/{type}`, or "exports" would be read as a report name
        // and answered with a 404 for an unknown report.
        Route::get('reports/exports', [ReportExportController::class, 'index']);
        Route::get('reports/exports/{id}', [ReportExportController::class, 'show']);
        Route::get('reports/exports/{id}/download', [ReportExportController::class, 'download']);

        Route::post('reports/{type}/export', [ReportExportController::class, 'store']);
        Route::get('reports/{type}', [ReportController::class, 'show']);
    });
