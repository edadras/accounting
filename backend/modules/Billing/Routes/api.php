<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Billing\Http\Controllers\InvoiceController;
use Modules\Billing\Http\Controllers\PlanController;
use Modules\Billing\Http\Controllers\SubscriptionController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('billing/plans', [PlanController::class, 'index']);

        Route::get('billing/subscription', [SubscriptionController::class, 'show']);
        Route::post('billing/subscription', [SubscriptionController::class, 'store']);

        // Before billing/subscription/{anything}: these are verbs, not ids.
        Route::post('billing/subscription/trial', [SubscriptionController::class, 'trial']);
        Route::post('billing/subscription/cancel', [SubscriptionController::class, 'cancel']);

        Route::get('billing/invoices', [InvoiceController::class, 'index']);
    });
