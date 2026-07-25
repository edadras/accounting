<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Money;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Support\CategoryRollup;
use Modules\Reports\Support\DateRange;
use Modules\Reports\Support\MoneyView;

/**
 * The pie/donut slices: every category at the requested depth, with its share.
 *
 * Anything beyond `limit` is folded into a single "other" slice rather than
 * dropped, so the slices always add up to the total the user sees elsewhere.
 */
final class CategoryBreakdownReport extends Report
{
    /** @return array<string, mixed> */
    public function handle(
        DateRange $range,
        int $limit = 12,
        int $depth = 1,
        string $type = Transaction::TYPE_EXPENSE,
    ): array {
        $currency = $this->baseCurrency();
        $limit = max(1, $limit);
        $depth = max(1, $depth);
        $type = $type === Transaction::TYPE_INCOME
            ? Transaction::TYPE_INCOME
            : Transaction::TYPE_EXPENSE;

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

        $buckets = array_values($buckets);
        $total = array_sum(array_column($buckets, 'total'));
        $totalCount = array_sum(array_column($buckets, 'transaction_count'));

        usort($buckets, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $slices = [];

        foreach (array_slice($buckets, 0, $limit) as $bucket) {
            $slices[] = [
                'category_id' => $bucket['category_id'],
                'path' => $bucket['path'],
                'name' => $bucket['name'],
                'depth' => $bucket['depth'],
                'total' => MoneyView::ofMinorUnits($bucket['total'], $currency),
                'transaction_count' => $bucket['transaction_count'],
                'percentage' => MoneyView::percentage($bucket['total'], $total),
            ];
        }

        $remainder = array_slice($buckets, $limit);

        if ($remainder !== []) {
            $remainderTotal = array_sum(array_column($remainder, 'total'));

            $slices[] = [
                'category_id' => null,
                'path' => null,
                'name' => 'other',
                'depth' => 0,
                'total' => MoneyView::ofMinorUnits($remainderTotal, $currency),
                'transaction_count' => array_sum(array_column($remainder, 'transaction_count')),
                'percentage' => MoneyView::percentage($remainderTotal, $total),
            ];
        }

        return [
            'report' => 'category-breakdown',
            'meta' => [
                ...$this->meta($range),
                'type' => $type,
                'depth' => $depth,
                'limit' => $limit,
            ],
            'slices' => $slices,
            'totals' => [
                'total' => MoneyView::of(Money::of($total, $currency)),
                'transaction_count' => $totalCount,
            ],
        ];
    }
}
