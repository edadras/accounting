<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Money;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Support\Bucket;
use Modules\Reports\Support\DateRange;
use Modules\Reports\Support\MoneyView;

/**
 * Money in versus money out, per period bucket.
 *
 * Transfers are absent by construction (see Report::flowQuery), so `net` is
 * the amount the workspace actually gained or lost — not the churn of its own
 * money between its own accounts.
 */
final class CashFlowReport extends Report
{
    /** @return array<string, mixed> */
    public function handle(DateRange $range, Bucket $bucket = Bucket::Month): array
    {
        $currency = $this->baseCurrency();
        $periods = $bucket->periodsFor($range);

        /** @var array<string, array{income:int,expense:int,transactions:int}> $totals */
        $totals = [];

        foreach ($periods as $period) {
            $totals[$period->key] = ['income' => 0, 'expense' => 0, 'transactions' => 0];
        }

        foreach ($this->dailyTotals($this->flowQuery($range), ['type']) as $row) {
            $key = $this->bucketKeyForDay($row->day, $bucket);

            if (! isset($totals[$key])) {
                continue;
            }

            $field = $row->type === Transaction::TYPE_INCOME ? 'income' : 'expense';
            $totals[$key][$field] += $row->total;
            $totals[$key]['transactions'] += $row->transactions;
        }

        $rows = [];
        $income = 0;
        $expense = 0;
        $transactions = 0;

        foreach ($periods as $period) {
            $slice = $totals[$period->key];
            $income += $slice['income'];
            $expense += $slice['expense'];
            $transactions += $slice['transactions'];

            $rows[] = [
                ...$period->toArray(),
                'income' => MoneyView::ofMinorUnits($slice['income'], $currency),
                'expense' => MoneyView::ofMinorUnits($slice['expense'], $currency),
                'net' => MoneyView::ofMinorUnits($slice['income'] - $slice['expense'], $currency),
                'transaction_count' => $slice['transactions'],
            ];
        }

        return [
            'report' => 'cash-flow',
            'meta' => $this->meta($range, $bucket),
            'periods' => $rows,
            'totals' => [
                'income' => MoneyView::of(Money::of($income, $currency)),
                'expense' => MoneyView::of(Money::of($expense, $currency)),
                'net' => MoneyView::of(Money::of($income - $expense, $currency)),
                'transaction_count' => $transactions,
            ],
        ];
    }
}
