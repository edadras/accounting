<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Banking\Http\Controllers\BankController;
use Modules\Banking\Http\Controllers\CheckController;
use Modules\Banking\Http\Controllers\LoanController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('banks', [BankController::class, 'index']);
        Route::post('banks', [BankController::class, 'store']);
        Route::get('banks/{id}', [BankController::class, 'show']);
        Route::patch('banks/{id}', [BankController::class, 'update']);
        Route::delete('banks/{id}', [BankController::class, 'destroy']);

        Route::get('checks', [CheckController::class, 'index']);
        Route::post('checks', [CheckController::class, 'store']);
        Route::get('checks/{id}', [CheckController::class, 'show']);
        Route::patch('checks/{id}', [CheckController::class, 'update']);
        Route::post('checks/{id}/clear', [CheckController::class, 'clear']);
        Route::delete('checks/{id}', [CheckController::class, 'destroy']);

        Route::get('loans', [LoanController::class, 'index']);
        Route::post('loans', [LoanController::class, 'store']);
        Route::get('loans/{id}', [LoanController::class, 'show']);
        Route::get('loans/{id}/schedule', [LoanController::class, 'schedule']);
        Route::post('loans/{id}/installments/{number}/pay', [LoanController::class, 'payInstallment'])
            ->whereNumber('number');
    });
