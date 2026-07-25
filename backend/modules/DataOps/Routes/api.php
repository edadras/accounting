<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\DataOps\Http\Controllers\AccountController;
use Modules\DataOps\Http\Controllers\DataExportController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // An account spans every workspace it belongs to, so deleting one is
        // not a workspace operation and sits outside that middleware.
        Route::delete('me', [AccountController::class, 'destroy']);
        Route::post('me/restore', [AccountController::class, 'restore']);

        Route::middleware(ResolveWorkspace::class)->group(function (): void {
            Route::get('exports', [DataExportController::class, 'index']);
            Route::post('exports', [DataExportController::class, 'store']);
            Route::get('exports/{id}/download', [DataExportController::class, 'download']);
        });
    });
