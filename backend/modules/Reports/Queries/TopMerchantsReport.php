<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Money;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Support\DateRange;
use Modules\Reports\Support\MoneyView;

/**
 * Who the money went to, grouped by `payee`.
 *
 * Transactions with no payee are not a merchant called "unknown" — they are
 * simply unlabelled, so they are counted in `unlabelled` instead of competing
 * for a place in the list.
 */
final class TopMerchantsReport extends Report
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

        $merchants = [];
        $unlabelledTotal = 0;
        $unlabelledCount = 0;

        foreach ($this->dailyTotals($this->flowQuery($range, $type), ['payee']) as $row) {
            $raw = $row['group']['payee'] ?? null;
            $payee = $raw === null ? '' : trim((string) $raw);

            if ($payee === '') {
                $unlabelledTotal += $row['total'];
                $unlabelledCount += $row['transactions'];

                continue;
            }

            $merchants[$payee] ??= ['total' => 0, 'transaction_count' => 0];
            $merchants[$payee]['total'] += $row['total'];
            $merchants[$payee]['transaction_count'] += $row['transactions'];
        }

        $labelledTotal = array_sum(array_column($merchants, 'total'));

        uasort($merchants, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $rows = [];
        $rank = 0;

        foreach (array_slice($merchants, 0, $limit, preserve_keys: true) as $payee => $merchant) {
            $rows[] = [
                'rank' => ++$rank,
                'payee' => $payee,
                'total' => MoneyView::ofMinorUnits($merchant['total'], $currency),
                'transaction_count' => $merchant['transaction_count'],
                'percentage' => MoneyView::percentage($merchant['total'], $labelledTotal),
            ];
        }

        return [
            'report' => 'top-merchants',
            'meta' => [
                ...$this->meta($range),
                'type' => $type,
                'limit' => $limit,
            ],
            'merchants' => $rows,
            'unlabelled' => [
                'total' => MoneyView::of(Money::of($unlabelledTotal, $currency)),
                'transaction_count' => $unlabelledCount,
            ],
            'totals' => [
                'total' => MoneyView::of(Money::of($labelledTotal, $currency)),
                'merchant_count' => count($merchants),
            ],
        ];
    }
}
