<?php

declare(strict_types=1);

namespace Modules\Security\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Audit\Support\AuditRecorder;
use Modules\Security\Exceptions\SecurityException;
use Modules\Security\Notifications\PasswordResetRequested;
use Modules\Security\Support\PasswordResetTokens;

/**
 * Forgotten-password recovery (docs/07-security.md §1).
 */
final class PasswordResetController
{
    public function __construct(
        private readonly PasswordResetTokens $tokens,
        private readonly AuditRecorder $recorder,
    ) {}

    /**
     * Always answers 200, whether or not the address is registered.
     *
     * "No account with that email" is a free membership oracle: point it at a
     * list of addresses and it tells you which of them bank here.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user !== null && ! $this->throttled($request, $data['email'])) {
            $user->notify(new PasswordResetRequested($this->tokens->issue($user)));
        }

        return response()->json([
            'data' => ['status' => 'password_reset_link_sent'],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! $this->tokens->consume($user, $data['token'])) {
            throw SecurityException::invalidResetToken();
        }

        $user->forceFill(['password' => $data['password']])->save();

        // Whoever prompted the reset may already hold a token from a stolen
        // session, and a new password that leaves those working resets nothing.
        $user->tokens()->delete();

        $this->recorder->record('auth.password_reset', $user);

        return response()->json([
            'data' => ['status' => 'password_reset'],
        ]);
    }

    private function throttled(Request $request, string $email): bool
    {
        $key = 'password-reset:'.sha1($email.'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
