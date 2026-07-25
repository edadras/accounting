<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Actions\CreateWorkspace;
use Modules\Core\Http\Concerns\ResolvesCurrentUser;
use Modules\Security\Actions\IssueTwoFactorChallenge;

final class AuthController
{
    use ResolvesCurrentUser;

    public function register(Request $request, CreateWorkspace $createWorkspace): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'locale' => ['nullable', Rule::in(['fa', 'en', 'tr', 'ar'])],
            'base_currency' => ['nullable', 'string', 'max:8'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'fa',
        ]);

        // A user with no workspace has nowhere to record anything, so the first
        // one is created with the account rather than as a separate step.
        $workspace = $createWorkspace->handle(
            owner: $user,
            name: $data['name'],
            type: 'personal',
            baseCurrency: $data['base_currency'] ?? 'IRR',
            locale: $user->locale,
        );

        app(AuditRecorder::class)->record('auth.registered', $user);

        return response()->json([
            'data' => [
                'token' => $user->createToken($this->deviceName($request))->plainTextToken,
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            ],
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['password'], $user->password)) {
            // One message for both cases: saying which was wrong tells an
            // attacker which emails are registered.
            // The row a breach investigation actually needs.
            app(AuditRecorder::class)->record(
                'auth.login_failed',
                after: ['email' => $data['email']],
            );

            return response()->json([
                'error' => [
                    'code' => 'invalid_credentials',
                    'message' => 'Wrong email or password.',
                ],
            ], 401);
        }

        // With a confirmed second factor the password stops being enough on its
        // own, so the answer here is a short-lived challenge rather than a
        // usable token; POST auth/2fa/verify trades it for one.
        if ($user->hasTwoFactorEnabled()) {
            app(AuditRecorder::class)->record('auth.two_factor_challenged', $user);

            return response()->json([
                'data' => ['two_factor_required' => true]
                    + app(IssueTwoFactorChallenge::class)->handle($user),
            ]);
        }

        app(AuditRecorder::class)->record('auth.login', $user);

        return response()->json([
            'data' => [
                'token' => $user->createToken($this->deviceName($request))->plainTextToken,
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'workspaces' => $user->workspaces()->get(['workspaces.id', 'workspaces.name', 'workspaces.type', 'workspaces.base_currency']),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // Revoke only the token that made this call, so signing out on a phone
        // does not sign the user out on their laptop.
        app(AuditRecorder::class)->record('auth.logout', $request->user());

        $this->currentUser($request)->currentAccessToken()->delete();

        return response()->json(status: 204);
    }

    private function deviceName(Request $request): string
    {
        return substr($request->header('X-Device-Name') ?? $request->userAgent() ?? 'unknown', 0, 120);
    }
}
