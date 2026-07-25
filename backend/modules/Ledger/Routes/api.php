<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Ledger\Http\Controllers\AccountController;
use Modules\Ledger\Http\Controllers\CategoryController;
use Modules\Ledger\Http\Controllers\TransactionController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('accounts', [AccountController::class, 'index']);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::get('accounts/{id}', [AccountController::class, 'show']);

        Route::get('categories', [CategoryController::class, 'index']);
        Route::post('categories', [CategoryController::class, 'store']);

        Route::get('transactions', [TransactionController::class, 'index']);
        Route::post('transactions', [TransactionController::class, 'store']);
        Route::get('transactions/{id}', [TransactionController::class, 'show']);
        Route::delete('transactions/{id}', [TransactionController::class, 'destroy']);
    });
