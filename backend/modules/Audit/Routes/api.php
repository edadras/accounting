<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Audit\Http\Controllers\AuditLogController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function (): void {
        // The caller's own sign-in history carries no workspace, so it sits
        // outside the workspace middleware.
        Route::get('me/security-log', [AuditLogController::class, 'personal']);

        Route::middleware(ResolveWorkspace::class)->group(function (): void {
            Route::get('audit-logs', [AuditLogController::class, 'index']);
        });
    });
