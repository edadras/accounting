<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Security\Http\Controllers\PasswordResetController;
use Modules\Security\Http\Controllers\TwoFactorController;

Route::prefix('api/v1')
    ->middleware('api')
    ->group(function (): void {
        // Nothing here can require a token: these are the routes for someone
        // who cannot finish signing in yet.
        Route::post('auth/2fa/verify', [TwoFactorController::class, 'verify']);
        Route::post('auth/forgot-password', [PasswordResetController::class, 'forgot']);
        Route::post('auth/reset-password', [PasswordResetController::class, 'reset']);

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('auth/2fa', [TwoFactorController::class, 'status']);
            Route::post('auth/2fa/enable', [TwoFactorController::class, 'enable']);
            Route::post('auth/2fa/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('auth/2fa/disable', [TwoFactorController::class, 'disable']);
        });
    });
