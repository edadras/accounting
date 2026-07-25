<?php

declare(strict_types=1);

namespace Modules\Security\Actions;

use App\Models\User;
use Modules\Security\Models\TwoFactorChallenge;

/**
 * Turns a correct password into a challenge instead of a session.
 *
 * The plaintext token exists only in the response this produces; the row keeps
 * nothing but its hash.
 */
final readonly class IssueTwoFactorChallenge
{
    /** @return array{challenge: string, expires_at: string} */
    public function handle(User $user): array
    {
        // Starting a new sign-in retires any half-finished one, so an abandoned
        // attempt cannot be completed by whoever finds it.
        $user->twoFactorChallenges()->whereNull('consumed_at')->delete();

        $token = TwoFactorChallenge::newToken();

        $challenge = TwoFactorChallenge::query()->create([
            'user_id' => $user->id,
            'token_hash' => TwoFactorChallenge::hashToken($token),
            'expires_at' => now()->addMinutes(TwoFactorChallenge::LIFETIME_MINUTES),
            'ip' => request()?->ip(),
        ]);

        return [
            'challenge' => $token,
            'expires_at' => $challenge->expires_at->toIso8601String(),
        ];
    }
}
