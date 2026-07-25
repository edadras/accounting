<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Search\Http\Controllers\SearchController;
use Modules\Search\Http\Controllers\SemanticSearchController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        // Registered before the bare `search` route so the literal segment is
        // never shadowed by a future wildcard on it.
        Route::get('search/semantic', SemanticSearchController::class);
        Route::get('search', SearchController::class);
    });
