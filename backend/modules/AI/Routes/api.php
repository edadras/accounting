<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\ChatController;
use Modules\AI\Http\Controllers\DraftController;
use Modules\AI\Http\Controllers\InsightController;
use Modules\AI\Http\Controllers\MediaDraftController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        // Natural language in, a draft out. Nothing here writes to the ledger.
        Route::post('ai/drafts', [DraftController::class, 'store']);
        Route::get('ai/drafts', [DraftController::class, 'index']);
        Route::get('ai/drafts/{id}', [DraftController::class, 'show']);

        // The user's decision, and the only route in this module that can
        // create a transaction.
        Route::post('ai/drafts/{id}/confirm', [DraftController::class, 'confirm']);
        Route::post('ai/drafts/{id}/discard', [DraftController::class, 'discard']);

        Route::post('ai/receipts', [MediaDraftController::class, 'receipt']);
        Route::post('ai/voice-notes', [MediaDraftController::class, 'voiceNote']);

        Route::post('ai/chat', ChatController::class);
        Route::get('ai/conversations/{id}', [ChatController::class, 'show']);
        Route::delete('ai/conversations/{id}', [ChatController::class, 'destroy']);

        Route::get('ai/insights', [InsightController::class, 'index']);
        Route::post('ai/insights/generate', [InsightController::class, 'generate']);
        Route::post('ai/insights/{id}/dismiss', [InsightController::class, 'dismiss']);
    });
