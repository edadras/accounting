<?php

declare(strict_types=1);

namespace Modules\Alerts\Scanners;

use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Modules\Alerts\Contracts\AlertScanner;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Support\AlertCandidate;
use Modules\Ledger\Models\Account;

/**
 * Accounts that have fallen below the floor the user set.
 *
 * The threshold is read in each account's own currency: a floor of "500" means
 * 500 of whatever that account holds, and converting it would make the warning
 * depend on today's rate.
 */
final class LowBalanceScanner implements AlertScanner
{
    public function type(): string
    {
        return AlertRule::TYPE_LOW_BALANCE;
    }

    public function scan(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $default = (int) $rule->setting('threshold', 0);
        $perAccount = $rule->setting('thresholds', []);
        $only = $rule->setting('account_ids');

        $query = Account::query()->whereNull('archived_at');

        if (is_array($only) && $only !== []) {
            $query->whereIn('id', $only);
        }

        foreach ($query->get() as $account) {
            $threshold = (int) (is_array($perAccount) ? ($perAccount[$account->id] ?? $default) : $default);
            $floor = Money::of($threshold, $account->currency);

            if (! $account->balance()->lessThan($floor)) {
                continue;
            }

            yield new AlertCandidate(
                type: $this->type(),

                // Once a day per account: a balance that stays low is one piece
                // of news, not one per scan, but it is worth repeating tomorrow.
                dedupeKey: "account:{$account->id}:low_balance:{$now->format('Y-m-d')}",
                payload: [
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'balance' => $account->balance(),
                    'threshold' => $floor,
                ],
                scheduledAt: $now,
            );
        }
    }
}
