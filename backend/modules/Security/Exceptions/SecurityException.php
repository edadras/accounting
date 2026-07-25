<?php

declare(strict_types=1);

namespace Modules\Security\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the security rules, carrying a stable machine-readable code.
 *
 * Implements Responsable so the framework renders it without needing a line in
 * `bootstrap/app.php`, matching the other modules.
 */
final class SecurityException extends RuntimeException implements Responsable
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function twoFactorAlreadyEnabled(): self
    {
        return new self(
            'two_factor_already_enabled',
            'Two-factor authentication is already active; disable it before enrolling again.',
            409,
        );
    }

    public static function twoFactorNotEnabled(): self
    {
        return new self(
            'two_factor_not_enabled',
            'Two-factor authentication is not set up for this account.',
            409,
        );
    }

    public static function invalidTwoFactorCode(): self
    {
        return new self('invalid_two_factor_code', 'That code is not valid.', 422);
    }

    public static function twoFactorConfirmationRequired(): self
    {
        return new self(
            'two_factor_confirmation_required',
            'Turning off two-factor authentication needs your password or a current code.',
            422,
        );
    }

    public static function challengeInvalid(): self
    {
        // Deliberately the same answer for an unknown, expired, spent or
        // exhausted challenge: a different one would say which it was.
        return new self(
            'two_factor_challenge_invalid',
            'This sign-in attempt is no longer valid. Start again.',
            401,
        );
    }

    public static function tooManyAttempts(int $retryAfter): self
    {
        return new self(
            'too_many_attempts',
            'Too many attempts. Try again shortly.',
            429,
            ['retry_after' => $retryAfter],
        );
    }

    public static function invalidResetToken(): self
    {
        // One answer for wrong, spent and expired: anything finer lets someone
        // probe which reset links are live.
        return new self(
            'invalid_reset_token',
            'This password reset link is not valid or has expired.',
            422,
        );
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
