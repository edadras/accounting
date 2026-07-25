<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\MarketData\Http\Controllers\MarketPriceController;
use Modules\MarketData\Http\Controllers\MarketRateController;

/*
 * Rates and prices are market data, but both endpoints answer in terms of a
 * workspace — the default quote currency on one, the caller's own positions on
 * the other — so both sit behind the same membership check as everything else.
 */
Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('market/rates', [MarketRateController::class, 'index']);
        Route::get('market/prices/{symbol}', [MarketPriceController::class, 'show']);
    });
