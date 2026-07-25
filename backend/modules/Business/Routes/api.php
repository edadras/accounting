<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Business\Http\Controllers\ContactController;
use Modules\Business\Http\Controllers\InvoiceController;
use Modules\Business\Http\Controllers\PaymentController;
use Modules\Business\Http\Controllers\ProjectController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('contacts', [ContactController::class, 'index']);
        Route::post('contacts', [ContactController::class, 'store']);
        Route::get('contacts/{id}', [ContactController::class, 'show']);

        Route::get('projects', [ProjectController::class, 'index']);
        Route::post('projects', [ProjectController::class, 'store']);
        Route::get('projects/{id}', [ProjectController::class, 'show']);
        Route::get('projects/{id}/profitability', [ProjectController::class, 'profitability']);

        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::post('invoices', [InvoiceController::class, 'store']);
        Route::get('invoices/{id}', [InvoiceController::class, 'show']);
        Route::post('invoices/{id}/void', [InvoiceController::class, 'void']);
        Route::post('invoices/{id}/pay', [InvoiceController::class, 'pay']);

        Route::get('payments', [PaymentController::class, 'index']);
        Route::post('payments', [PaymentController::class, 'store']);
        Route::get('payments/{id}', [PaymentController::class, 'show']);
    });
