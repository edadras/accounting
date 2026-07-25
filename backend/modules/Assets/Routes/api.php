<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Assets\Http\Controllers\AssetController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('assets', [AssetController::class, 'index']);
        Route::post('assets', [AssetController::class, 'store']);
        Route::get('assets/{id}', [AssetController::class, 'show']);
        Route::get('assets/{id}/depreciation', [AssetController::class, 'depreciation']);
    });
