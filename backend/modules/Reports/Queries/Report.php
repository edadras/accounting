<?php

declare(strict_types=1);

namespace Modules\Reports\Queries;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;
use Modules\Reports\Support\Bucket;
use Modules\Reports\Support\DateRange;

/**
 * Shared rules every report obeys. Each one is a correctness rule, not a
 * convenience: breaking any of them makes the report lie.
 */
abstract class Report
{
    public function __construct(protected readonly WorkspaceContext $context) {}

    protected function baseCurrency(): Currency
    {
        return Currency::of($this->context->baseCurrency());
    }

    /**
     * Real money-in / money-out inside the range.
     *
     * Two rules are enforced here rather than in each report:
     *
     *  1. Transfers are excluded. Moving money between the user's own accounts
     *     is neither income nor spending; counting it would inflate every
     *     "where did my money go" answer and double-count the same lira.
     *  2. Only rows already valued in the workspace's base currency are summed.
     *     `amount`/`currency` are per-transaction and mixed, so summing them
     *     would add lira to dollars; `base_amount` is one yardstick, and
     *     pinning `base_currency` keeps it one even if the workspace changed
     *     its base currency at some point.
     *
     * The workspace global scope supplies the tenant filter — this query is
     * never allowed to reach across workspaces.
     *
     * @return Builder<Transaction>
     */
    protected function flowQuery(DateRange $range, ?string $type = null): Builder
    {
        $query = Transaction::query()
            ->whereIn('type', [Transaction::TYPE_INCOME, Transaction::TYPE_EXPENSE])
            ->where('base_currency', $this->baseCurrency()->code)
            ->where('occurred_at', '>=', $range->start)
            ->where('occurred_at', '<', $range->endExclusive);

        return $type === null ? $query : $query->where('type', $type);
    }

    /**
     * Per-day totals, grouped by whatever extra columns a report needs.
     *
     * Grouping happens per calendar day in SQL and is rolled up into buckets in
     * PHP: `DATE()` is the one date function MySQL and SQLite agree on, and a
     * year of days is a few hundred rows.
     *
     * The columns a report grouped by are kept together under `group` rather
     * than sitting beside the totals: which of them a row carries depends on
     * what the caller asked for, so they cannot be named in the row's type,
     * and the totals stay typed as the integers the arithmetic needs.
     *
     * @param  Builder<Transaction>  $query
     * @param  list<string>  $groupBy
     * @return Collection<int, array{day: string, total: int, transactions: int, group: array<string, mixed>}>
     */
    protected function dailyTotals(Builder $query, array $groupBy = []): Collection
    {
        $columns = array_merge(['DATE(occurred_at) as day'], $groupBy);
        $grouping = array_merge(['DATE(occurred_at)'], $groupBy);

        return $query
            ->selectRaw(implode(', ', $columns).', SUM(base_amount) as total, COUNT(*) as transactions')
            ->groupByRaw(implode(', ', $grouping))
            ->get()
            ->map(function (Transaction $row) use ($groupBy): array {
                $group = [];

                foreach ($groupBy as $column) {
                    $group[$column] = $row->getAttribute($column);
                }

                return [
                    'day' => (string) $row->getAttribute('day'),
                    'total' => (int) $row->getAttribute('total'),
                    'transactions' => (int) $row->getAttribute('transactions'),
                    'group' => $group,
                ];
            });
    }

    protected function bucketKeyForDay(string $day, Bucket $bucket): string
    {
        return $bucket->keyFor(CarbonImmutable::parse($day));
    }

    /**
     * The report's own description of the window and granularity it answers
     * for, echoed back so a chart never has to guess what it is plotting.
     *
     * @return array<string, mixed>
     */
    protected function meta(DateRange $range, ?Bucket $bucket = null): array
    {
        return array_filter([
            ...$range->toArray(),
            'bucket' => $bucket?->value,
            'currency' => $this->baseCurrency()->code,
            'boundaries' => 'from 00:00:00 inclusive, to 23:59:59 inclusive',
        ], static fn (mixed $value): bool => $value !== null);
    }
}
