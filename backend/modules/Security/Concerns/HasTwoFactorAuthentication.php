<?php

declare(strict_types=1);

namespace Modules\Security\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Security\Models\TwoFactorChallenge;
use Modules\Security\Support\RecoveryCodes;

/**
 * The second-factor state of a user account.
 *
 * `two_factor_secret` and `two_factor_recovery_codes` are encrypted casts on
 * the model (docs/07-security.md §3), so everything here works in plaintext and
 * the database never sees any.
 */
trait HasTwoFactorAuthentication
{
    public function twoFactorChallenges(): HasMany
    {
        return $this->hasMany(TwoFactorChallenge::class);
    }

    /**
     * A secret alone is not protection: it is only in force once the user has
     * proved they can read codes from it.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isEnrollingInTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at === null;
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        $stored = $this->two_factor_recovery_codes;

        if (! is_string($stored) || $stored === '') {
            return [];
        }

        $codes = json_decode($stored, true);

        return is_array($codes) ? array_values(array_map(strval(...), $codes)) : [];
    }

    /** @return list<string> The codes, which are shown to the user exactly once. */
    public function regenerateRecoveryCodes(): array
    {
        $codes = RecoveryCodes::generate();

        $this->storeRecoveryCodes($codes);

        return $codes;
    }

    /** Spends a recovery code. Each one works exactly once. */
    public function consumeRecoveryCode(string $code): bool
    {
        $given = Str::upper(trim($code));

        if ($given === '') {
            return false;
        }

        $remaining = [];
        $matched = false;

        foreach ($this->recoveryCodes() as $stored) {
            if (! $matched && hash_equals(Str::upper($stored), $given)) {
                $matched = true;

                continue;
            }

            $remaining[] = $stored;
        }

        if (! $matched) {
            return false;
        }

        $this->storeRecoveryCodes($remaining);

        return true;
    }

    public function forgetTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        // Anything half-finished under the old secret must not still be
        // exchangeable for a token.
        $this->twoFactorChallenges()->whereNull('consumed_at')->delete();
    }

    /** @param list<string> $codes */
    private function storeRecoveryCodes(array $codes): void
    {
        $this->forceFill([
            'two_factor_recovery_codes' => json_encode(array_values($codes)),
        ])->save();
    }
}
