<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Money;
use Modules\Reports\Support\Bucket;
use Modules\Reports\Support\DateRange;
use Modules\Reports\Support\MoneyView;

/** One line on a chart: the total of a single transaction type per bucket. */
abstract class TrendReport extends Report
{
    abstract protected function transactionType(): string;

    abstract protected function name(): string;

    /** @return array<string, mixed> */
    public function handle(DateRange $range, Bucket $bucket = Bucket::Month): array
    {
        $currency = $this->baseCurrency();
        $periods = $bucket->periodsFor($range);

        $totals = [];
        $counts = [];

        foreach ($periods as $period) {
            $totals[$period->key] = 0;
            $counts[$period->key] = 0;
        }

        $query = $this->flowQuery($range, $this->transactionType());

        foreach ($this->dailyTotals($query) as $row) {
            $key = $this->bucketKeyForDay($row['day'], $bucket);

            if (! isset($totals[$key])) {
                continue;
            }

            $totals[$key] += $row['total'];
            $counts[$key] += $row['transactions'];
        }

        $rows = [];
        $sum = 0;
        $transactions = 0;

        foreach ($periods as $period) {
            $sum += $totals[$period->key];
            $transactions += $counts[$period->key];

            $rows[] = [
                ...$period->toArray(),
                'total' => MoneyView::ofMinorUnits($totals[$period->key], $currency),
                'transaction_count' => $counts[$period->key],
            ];
        }

        $periodCount = count($periods);

        return [
            'report' => $this->name(),
            'meta' => $this->meta($range, $bucket),
            'periods' => $rows,
            'totals' => [
                'total' => MoneyView::of(Money::of($sum, $currency)),
                'transaction_count' => $transactions,
                // Integer division: the average is money too, so it stays in
                // minor units and never becomes a float.
                'average_per_period' => MoneyView::of(Money::of(
                    $periodCount === 0 ? 0 : intdiv($sum, $periodCount),
                    $currency,
                )),
            ],
        ];
    }
}
