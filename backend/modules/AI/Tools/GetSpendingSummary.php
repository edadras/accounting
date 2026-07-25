<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use Modules\AI\Contracts\Tool;
use Modules\AI\Tools\Concerns\ReadsWindow;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

/**
 * "Where did my money go this month?"
 *
 * Aggregated in PHP over a scoped Eloquent query rather than in raw SQL: the
 * model never gets to influence a query string, only which of six shapes runs
 * (docs/07-security.md §5.1).
 */
final class GetSpendingSummary implements Tool
{
    use ReadsWindow;

    /** A cap, so a chat turn can never pull the whole ledger into memory. */
    private const MAX_SCAN = 5000;

    public function __construct(private readonly WorkspaceContext $context) {}

    public function name(): string
    {
        return 'get_spending_summary';
    }

    public function description(): string
    {
        return 'Total expenses over a date window, broken down by top-level category or by month.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Inclusive start date, YYYY-MM-DD.'],
                'to' => ['type' => 'string', 'description' => 'Inclusive end date, YYYY-MM-DD.'],
                'group_by' => ['type' => 'string', 'enum' => ['category', 'month']],
            ],
        ];
    }

    public function run(array $arguments): array
    {
        [$from, $to] = $this->window($arguments);
        $base = $this->context->baseCurrency();
        $groupBy = ($arguments['group_by'] ?? 'category') === 'month' ? 'month' : 'category';

        $rows = Transaction::query()
            ->ofType(Transaction::TYPE_EXPENSE)
            ->between($from, $to)
            ->where('base_currency', $base)
            ->orderBy('occurred_at')
            ->limit(self::MAX_SCAN)
            ->get(['id', 'category_id', 'base_amount', 'occurred_at']);

        $total = (int) $rows->sum('base_amount');
        $labels = $groupBy === 'category' ? $this->categoryLabels() : [];

        $buckets = [];

        foreach ($rows as $row) {
            $key = $groupBy === 'month'
                ? $row->occurred_at->format('Y-m')
                : ($labels[$row->category_id]['root'] ?? 'uncategorized');

            $buckets[$key] ??= ['key' => $key, 'label' => $key, 'amount' => 0, 'count' => 0];
            $buckets[$key]['amount'] += (int) $row->base_amount;
            $buckets[$key]['count']++;
        }

        $groups = array_values($buckets);
        usort($groups, static fn (array $a, array $b) => $b['amount'] <=> $a['amount']);

        foreach ($groups as $index => $group) {
            $groups[$index]['share'] = $total > 0 ? round($group['amount'] / $total, 4) : 0.0;
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => $base,
            'group_by' => $groupBy,
            'total' => $total,
            'transaction_count' => $rows->count(),
            'groups' => $groups,
        ];
    }

    /**
     * Every category mapped to the name of the root it hangs under, so
     * "Restaurant" reports under "Home" the way the user's tree is drawn.
     *
     * @return array<string, array{root: string}>
     */
    private function categoryLabels(): array
    {
        $categories = Category::query()->get(['id', 'name', 'path']);
        $byPath = [];

        foreach ($categories as $category) {
            $byPath[(string) $category->path] = (string) $category->name;
        }

        $labels = [];

        foreach ($categories as $category) {
            $segments = array_values(array_filter(explode('/', (string) $category->path)));
            $rootPath = '/'.($segments[0] ?? '');
            $labels[$category->id] = ['root' => $byPath[$rootPath] ?? (string) $category->name];
        }

        return $labels;
    }
}
