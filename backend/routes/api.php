<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\AuthController;
use Modules\Core\Http\Controllers\MemberController;
use Modules\Core\Http\Controllers\WorkspaceController;
use Modules\Core\Http\Middleware\ResolveWorkspace;

Route::prefix('v1')->group(function (): void {
    Route::post('auth/register', [AuthController::class, 'register']);
    Route::post('auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        // `/me` lives in DataOps' AccountController: the account's own
        // lifecycle — including a scheduled deletion — is that module's, and
        // two routes for one path meant the first one registered quietly won.

        // Workspace listing cannot itself require a workspace header — this is
        // how the client discovers which ids it may send.
        Route::get('workspaces', [WorkspaceController::class, 'index']);
        Route::post('workspaces', [WorkspaceController::class, 'store']);

        // Accepting is how a person gets INTO a workspace, so it cannot sit
        // behind the middleware that requires already being in one. The token
        // identifies the workspace.
        Route::post('invitations/{token}/accept', [MemberController::class, 'accept']);

        Route::middleware(ResolveWorkspace::class)->group(function (): void {
            Route::get('members', [MemberController::class, 'index']);
            Route::patch('members/{id}', [MemberController::class, 'updateRole']);
            Route::delete('members/{id}', [MemberController::class, 'destroy']);

            Route::get('invitations', [MemberController::class, 'invitations']);
            Route::post('invitations', [MemberController::class, 'invite']);
            Route::delete('invitations/{id}', [MemberController::class, 'revoke']);
        });
    });
});
