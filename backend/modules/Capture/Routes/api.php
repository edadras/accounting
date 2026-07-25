<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Capture\Http\Controllers\CaptureController;
use Modules\Capture\Http\Controllers\EmailWebhookController;
use Modules\Capture\Http\Controllers\IngestAliasController;
use Modules\Capture\Http\Middleware\VerifyCaptureSecret;
use Modules\Core\Http\Middleware\ResolveWorkspace;

// A mail provider has no session and no workspace header to send, so this route
// cannot sit in the group below. The shared secret is its authentication and
// the recipient address is how it finds the workspace — both checked before
// anything reads the body.
Route::prefix('api/v1')
    ->middleware(['api', VerifyCaptureSecret::class])
    ->group(function (): void {
        Route::post('capture/email', EmailWebhookController::class);
    });

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        // Three ways to describe a payment. All three stop at a draft.
        Route::post('capture/text', [CaptureController::class, 'text']);
        Route::post('capture/sms', [CaptureController::class, 'sms']);
        Route::post('capture/qr', [CaptureController::class, 'qr']);

        Route::get('capture/messages', [CaptureController::class, 'index']);
        Route::get('capture/messages/{id}', [CaptureController::class, 'show']);

        // The user's decision, and the only route in this module that can
        // create a transaction.
        Route::post('capture/messages/{id}/confirm', [CaptureController::class, 'confirm']);

        Route::get('capture/ingest-aliases', [IngestAliasController::class, 'index']);
        Route::post('capture/ingest-aliases', [IngestAliasController::class, 'store']);
        Route::delete('capture/ingest-aliases/{id}', [IngestAliasController::class, 'destroy']);
    });
