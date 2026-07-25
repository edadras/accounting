<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Money;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Support\DateRange;
use Modules\Reports\Support\MoneyView;

/**
 * Which accounts the activity flowed through.
 *
 * Transfers are excluded, which is what makes this readable: otherwise the
 * account a user sweeps their salary through every month would top the list
 * without a single lira ever having been spent from it.
 */
final class TopAccountsReport extends Report
{
    /** @return array<string, mixed> */
    public function handle(
        DateRange $range,
        int $limit = 10,
        string $type = Transaction::TYPE_EXPENSE,
    ): array {
        $currency = $this->baseCurrency();
        $limit = max(1, $limit);
        $type = $type === Transaction::TYPE_INCOME
            ? Transaction::TYPE_INCOME
            : Transaction::TYPE_EXPENSE;

        $accounts = [];

        foreach ($this->dailyTotals($this->flowQuery($range, $type), ['account_id']) as $row) {
            $id = (string) $row->account_id;

            $accounts[$id] ??= ['total' => 0, 'transaction_count' => 0];
            $accounts[$id]['total'] += $row->total;
            $accounts[$id]['transaction_count'] += $row->transactions;
        }

        $total = array_sum(array_column($accounts, 'total'));

        uasort($accounts, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $names = Account::query()
            ->withTrashed()
            ->whereIn('id', array_keys($accounts))
            ->get()
            ->keyBy('id');

        $rows = [];
        $rank = 0;

        foreach (array_slice($accounts, 0, $limit, preserve_keys: true) as $id => $account) {
            $model = $names->get($id);

            $rows[] = [
                'rank' => ++$rank,
                'account_id' => $id,
                'name' => $model?->name,
                'type' => $model?->type,
                'currency' => $model?->currency,
                'total' => MoneyView::ofMinorUnits($account['total'], $currency),
                'transaction_count' => $account['transaction_count'],
                'percentage' => MoneyView::percentage($account['total'], $total),
            ];
        }

        return [
            'report' => 'top-accounts',
            'meta' => [
                ...$this->meta($range),
                'type' => $type,
                'limit' => $limit,
            ],
            'accounts' => $rows,
            'totals' => [
                'total' => MoneyView::of(Money::of($total, $currency)),
                'account_count' => count($accounts),
            ],
        ];
    }
}
