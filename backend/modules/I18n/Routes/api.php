<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\I18n\Http\Controllers\TranslationController;

Route::prefix('api/v1')->middleware('api')->group(function (): void {
    // Deliberately open: the sign-in screen needs its own words before anyone
    // has a token, and a dictionary is not private data.
    Route::get('translations/{locale}', [TranslationController::class, 'show']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::put('translations', [TranslationController::class, 'upsert']);
    });
});
