<?php

declare(strict_types=1);

namespace Modules\Capture\Support;

use Modules\Ledger\Models\Account;

/**
 * Turns «۶۰۳۷***۱۲۳۴» into one of the user's accounts, or into nothing.
 *
 * Banks mask the middle, so the last four digits are all there is to match on.
 * Four digits are not unique across a wallet full of cards, and picking the
 * first of several would attach a purchase to the wrong account silently — so
 * an ambiguous fragment resolves to null and the user is asked, exactly as an
 * unrecognised one is.
 */
final class AccountMatcher
{
    /** @return array{account: Account|null, reason: string} */
    public static function match(?string $fragment): array
    {
        $digits = $fragment === null ? '' : (string) preg_replace('/\D/', '', $fragment);

        if (strlen($digits) < 4) {
            return ['account' => null, 'reason' => 'no_account_fragment'];
        }

        $last4 = substr($digits, -4);

        $candidates = Account::query()
            ->whereNull('archived_at')
            ->where(fn ($query) => $query
                ->where('card_last4', $last4)
                ->orWhere('iban', 'like', '%'.$last4))
            ->limit(2)
            ->get();

        return match ($candidates->count()) {
            0 => ['account' => null, 'reason' => 'account_unmatched'],
            1 => ['account' => $candidates->first(), 'reason' => 'matched_last4'],
            default => ['account' => null, 'reason' => 'account_ambiguous'],
        };
    }

    /** @return array{id: string, name: string, currency: string, reason: string}|null */
    public static function suggestion(?Account $account, string $reason): ?array
    {
        if ($account === null) {
            return null;
        }

        return [
            'id' => (string) $account->id,
            'name' => (string) $account->name,
            'currency' => (string) $account->currency,
            'reason' => $reason,
        ];
    }
}
