<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\ResolveWorkspace;
use Modules\Family\Http\Controllers\AllowanceController;
use Modules\Family\Http\Controllers\FamilyMemberController;
use Modules\Family\Http\Controllers\MemberSpendingController;

Route::prefix('api/v1')
    ->middleware(['api', 'auth:sanctum', ResolveWorkspace::class])
    ->group(function (): void {
        Route::get('family/members', [FamilyMemberController::class, 'index']);
        Route::post('family/members', [FamilyMemberController::class, 'store']);
        Route::get('family/members/{member}', [FamilyMemberController::class, 'show']);
        Route::patch('family/members/{member}', [FamilyMemberController::class, 'update']);

        Route::get('family/spending', MemberSpendingController::class);

        Route::get('family/allowances', [AllowanceController::class, 'index']);
        Route::post('family/members/{member}/allowance', [AllowanceController::class, 'store']);
    });
