<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Sync\Http\Controllers\DeviceController;
use Modules\Sync\Http\Controllers\SyncController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::post('sync/push', [SyncController::class, 'push']);
        Route::get('sync/pull', [SyncController::class, 'pull']);

        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::delete('devices/{id}', [DeviceController::class, 'destroy']);
    });
