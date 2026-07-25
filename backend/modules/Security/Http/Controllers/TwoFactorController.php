<?php

declare(strict_types=1);

namespace Modules\Security\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Http\Concerns\ResolvesCurrentUser;
use Modules\Security\Exceptions\SecurityException;
use Modules\Security\Models\TwoFactorChallenge;
use Modules\Security\Support\TwoFactorAuthenticator;

/**
 * Enrolment in, and use of, TOTP two-factor authentication
 * (docs/07-security.md §1).
 */
final class TwoFactorController
{
    use ResolvesCurrentUser;

    public function __construct(
        private readonly TwoFactorAuthenticator $authenticator,
        private readonly AuditRecorder $recorder,
    ) {}

    /**
     * Step one: mint a secret and hand back the URI an authenticator app scans.
     *
     * Nothing changes about signing in yet — an unconfirmed secret would lock
     * the user out of their own books if their app never received it.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if ($user->hasTwoFactorEnabled()) {
            throw SecurityException::twoFactorAlreadyEnabled();
        }

        $secret = $this->authenticator->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'data' => [
                'secret' => $secret,
                'otpauth_uri' => $this->authenticator->provisioningUri(
                    (string) config('app.name', 'Finora'),
                    (string) $user->email,
                    $secret,
                ),
                'confirmed' => false,
            ],
        ]);
    }

    /** Step two: a code from the app proves it holds the secret, and 2FA goes live. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);

        $user = $this->currentUser($request);

        if ($user->hasTwoFactorEnabled()) {
            throw SecurityException::twoFactorAlreadyEnabled();
        }

        if (! $user->isEnrollingInTwoFactor()) {
            throw SecurityException::twoFactorNotEnabled();
        }

        $this->throttle('2fa-confirm:'.$user->id);

        if (! $this->authenticator->verify((string) $user->two_factor_secret, $data['code'])) {
            $this->recorder->record('auth.two_factor_failed', $user, after: ['stage' => 'confirm']);

            throw SecurityException::invalidTwoFactorCode();
        }

        RateLimiter::clear('2fa-confirm:'.$user->id);

        $confirmedAt = now();

        $user->forceFill(['two_factor_confirmed_at' => $confirmedAt])->save();
        $codes = $user->regenerateRecoveryCodes();

        $this->recorder->record('auth.two_factor_enabled', $user);

        return response()->json([
            'data' => [
                'confirmed_at' => $confirmedAt->toIso8601String(),
                // Shown once. They are encrypted at rest and never returned again.
                'recovery_codes' => $codes,
            ],
        ]);
    }

    /**
     * Turning the second factor off is itself a sensitive act, so it costs
     * either the password or a current code.
     */
    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string'],
            'code' => ['nullable', 'string', 'max:32'],
        ]);

        $user = $this->currentUser($request);

        if (! $user->hasTwoFactorEnabled() && ! $user->isEnrollingInTwoFactor()) {
            throw SecurityException::twoFactorNotEnabled();
        }

        $password = $data['password'] ?? '';
        $code = $data['code'] ?? '';

        if ($password === '' && $code === '') {
            throw SecurityException::twoFactorConfirmationRequired();
        }

        $this->throttle('2fa-disable:'.$user->id);

        $confirmed = ($password !== '' && Hash::check($password, $user->password))
            || ($code !== '' && $this->authenticator->verify((string) $user->two_factor_secret, $code))
            || ($code !== '' && $user->consumeRecoveryCode($code));

        if (! $confirmed) {
            $this->recorder->record('auth.two_factor_failed', $user, after: ['stage' => 'disable']);

            throw SecurityException::twoFactorConfirmationRequired();
        }

        RateLimiter::clear('2fa-disable:'.$user->id);

        $user->forgetTwoFactor();

        $this->recorder->record('auth.two_factor_disabled', $user);

        return response()->json(['data' => ['two_factor_enabled' => false]]);
    }

    /**
     * The second half of signing in: a challenge plus a code (or a recovery
     * code) becomes the token that login did not hand out.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:32'],
        ]);

        $challenge = TwoFactorChallenge::query()
            ->where('token_hash', TwoFactorChallenge::hashToken($data['challenge']))
            ->first();

        if ($challenge === null || ! $challenge->isUsable()) {
            throw SecurityException::challengeInvalid();
        }

        $user = $challenge->user;

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            throw SecurityException::challengeInvalid();
        }

        $verified = $this->authenticator->verify((string) $user->two_factor_secret, $data['code'])
            || $user->consumeRecoveryCode($data['code']);

        if (! $verified) {
            $challenge->recordFailure();

            $this->recorder->record('auth.two_factor_failed', $user, after: [
                'stage' => 'login',
                'attempts' => $challenge->attempts,
            ]);

            // Burning through the attempts ends this sign-in rather than
            // leaving a challenge to keep guessing against.
            throw $challenge->isExhausted()
                ? SecurityException::challengeInvalid()
                : SecurityException::invalidTwoFactorCode();
        }

        $challenge->consume();

        $this->recorder->record('auth.login', $user, after: ['two_factor' => true]);

        return response()->json([
            'data' => [
                'token' => $user->createToken($this->deviceName($request))->plainTextToken,
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
                'workspaces' => $user->workspaces()->get([
                    'workspaces.id', 'workspaces.name', 'workspaces.type', 'workspaces.base_currency',
                ]),
            ],
        ]);
    }

    /** The current state, so a client can render the security screen. */
    public function status(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        return response()->json([
            'data' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending_confirmation' => $user->isEnrollingInTwoFactor(),
                'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
                'recovery_codes_remaining' => count($user->recoveryCodes()),
            ],
        ]);
    }

    private function throttle(string $key, int $maxAttempts = 5, int $decaySeconds = 60): void
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw SecurityException::tooManyAttempts(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    private function deviceName(Request $request): string
    {
        return substr($request->header('X-Device-Name') ?? $request->userAgent() ?? 'unknown', 0, 120);
    }
}
