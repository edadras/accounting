<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Documents\Http\Controllers\DocumentController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('documents', [DocumentController::class, 'index']);
        Route::post('documents', [DocumentController::class, 'store']);
        Route::get('documents/{id}', [DocumentController::class, 'show']);
        Route::delete('documents/{id}', [DocumentController::class, 'destroy']);

        Route::post('documents/{id}/attach', [DocumentController::class, 'attach']);
        Route::post('documents/{id}/detach', [DocumentController::class, 'detach']);
    });
