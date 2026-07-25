<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Money;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Support\CategoryRollup;
use Modules\Reports\Support\DateRange;
use Modules\Reports\Support\MoneyView;

/**
 * The biggest categories in the range, rolled up to a chosen depth.
 *
 * Transfers carry no category and are excluded from the query anyway, so they
 * can never surface here as a phantom spending category.
 */
final class TopCategoriesReport extends Report
{
    /** @return array<string, mixed> */
    public function handle(
        DateRange $range,
        int $limit = 10,
        int $depth = 1,
        string $type = Transaction::TYPE_EXPENSE,
    ): array {
        $currency = $this->baseCurrency();
        $limit = max(1, $limit);
        $depth = max(1, $depth);
        $type = $type === Transaction::TYPE_INCOME
            ? Transaction::TYPE_INCOME
            : Transaction::TYPE_EXPENSE;

        $buckets = $this->rollUp($range, $type, $depth);

        $total = array_sum(array_column($buckets, 'total'));
        $totalCount = array_sum(array_column($buckets, 'transaction_count'));

        usort($buckets, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $top = array_slice($buckets, 0, $limit);
        $rows = [];
        $rank = 0;

        foreach ($top as $bucket) {
            $rows[] = [
                'rank' => ++$rank,
                'category_id' => $bucket['category_id'],
                'path' => $bucket['path'],
                'name' => $bucket['name'],
                'depth' => $bucket['depth'],
                'total' => MoneyView::ofMinorUnits($bucket['total'], $currency),
                'transaction_count' => $bucket['transaction_count'],
                'percentage' => MoneyView::percentage($bucket['total'], $total),
            ];
        }

        $shown = array_sum(array_column($top, 'total'));

        return [
            'report' => 'top-categories',
            'meta' => [
                ...$this->meta($range),
                'type' => $type,
                'depth' => $depth,
                'limit' => $limit,
            ],
            'categories' => $rows,
            'other' => [
                'total' => MoneyView::of(Money::of($total - $shown, $currency)),
                'category_count' => max(0, count($buckets) - count($top)),
            ],
            'totals' => [
                'total' => MoneyView::of(Money::of($total, $currency)),
                'transaction_count' => $totalCount,
            ],
        ];
    }

    /**
     * @return list<array{key:string,category_id:?string,path:?string,name:string,depth:int,total:int,transaction_count:int}>
     */
    private function rollUp(DateRange $range, string $type, int $depth): array
    {
        $rollup = new CategoryRollup;
        $buckets = [];

        foreach ($this->dailyTotals($this->flowQuery($range, $type), ['category_id']) as $row) {
            $bucket = $rollup->bucketFor(
                $row->category_id === null ? null : (string) $row->category_id,
                $depth,
            );

            $key = $bucket['key'];

            $buckets[$key] ??= [...$bucket, 'total' => 0, 'transaction_count' => 0];
            $buckets[$key]['total'] += $row->total;
            $buckets[$key]['transaction_count'] += $row->transactions;
        }

        return array_values($buckets);
    }
}
