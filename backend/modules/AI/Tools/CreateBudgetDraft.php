<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Modules\AI\Contracts\Tool;
use Modules\AI\Tools\Concerns\ReadsWindow;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;

/**
 * "بودجه ماه آینده را تنظیم کن" — proposed from what was actually spent, and
 * written nowhere.
 *
 * The governing rule of docs/08-ai-layer.md is in its first paragraph: AI
 * never creates or changes financial data without the user's confirmation. It
 * proposes; the user decides. A tool named `create_…` that a model may call on
 * its own initiative is the single most likely place for that rule to be lost,
 * so it is enforced structurally rather than by discipline:
 *
 *   This class does not import Modules\Budget. Not the model, not an action,
 *   not the facade. There is no expression anywhere in it that could reach a
 *   Budget row, which means no future edit can make it write one by accident
 *   and no reviewer has to read it closely to be sure. Persisting a draft is
 *   the client's job, through the ordinary POST /budgets endpoint that a human
 *   pressed a button to reach.
 *
 * The proposal itself is arithmetic, not a model: average monthly spend per
 * root category over a lookback window. That is both explainable — the draft
 * carries the months and the totals it was derived from — and correct, which
 * a model guessing at plausible round numbers would not be.
 */
final class CreateBudgetDraft implements Tool
{
    use ReadsWindow;

    private const DEFAULT_LOOKBACK_MONTHS = 3;

    private const MAX_LOOKBACK_MONTHS = 24;

    /** A cap, so one chat turn can never pull the whole ledger into memory. */
    private const MAX_SCAN = 5000;

    /**
     * Categories under this share of total spend are left out of the draft.
     *
     * A budget line for the 0.4% that went on stamps is noise the user has to
     * delete; the overall line still covers it.
     */
    private const MIN_SHARE = 0.02;

    public function __construct(private readonly WorkspaceContext $context) {}

    public function name(): string
    {
        return 'create_budget_draft';
    }

    public function description(): string
    {
        return 'Propose a budget for an upcoming period from recent spending. Returns a draft only — it never creates or changes a budget, which the user must confirm separately.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'Any date inside the month the budget is for, YYYY-MM-DD. Defaults to next month.',
                ],
                'months' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LOOKBACK_MONTHS,
                    'description' => 'How many past months of spending to average. Defaults to '.self::DEFAULT_LOOKBACK_MONTHS.'.',
                ],
            ],
        ];
    }

    public function run(array $arguments): array
    {
        $now = CarbonImmutable::now();
        $target = ($this->date($arguments['period'] ?? null) ?? $now->addMonthNoOverflow())->startOfMonth();

        $months = max(1, min(self::MAX_LOOKBACK_MONTHS, (int) ($arguments['months'] ?? self::DEFAULT_LOOKBACK_MONTHS)));

        // Whole calendar months, ending with the last one that is complete. A
        // part-month would drag every average down by however far into the
        // month today happens to be.
        $to = $now->startOfMonth()->subDay()->endOfDay();
        $from = $to->startOfMonth()->subMonthsNoOverflow($months - 1)->startOfMonth();

        $currency = Currency::of($this->context->baseCurrency());

        $rows = Transaction::query()
            ->ofType(Transaction::TYPE_EXPENSE)
            ->between($from, $to)
            ->where('base_currency', $currency->code)
            ->orderBy('occurred_at')
            ->limit(self::MAX_SCAN)
            ->get(['id', 'category_id', 'base_amount', 'occurred_at']);

        $roots = $this->rootCategories();
        $observed = [];
        $buckets = [];
        $total = 0;

        foreach ($rows as $row) {
            $observed[$row->occurred_at->format('Y-m')] = true;

            $root = $roots[$row->category_id] ?? null;
            $key = $root['id'] ?? 'uncategorized';

            $buckets[$key] ??= [
                'category_id' => $root['id'] ?? null,
                'category' => $root['name'] ?? 'uncategorized',
                'total_spent' => 0,
                'transaction_count' => 0,
            ];

            $buckets[$key]['total_spent'] += (int) $row->base_amount;
            $buckets[$key]['transaction_count']++;
            $total += (int) $row->base_amount;
        }

        // Months that saw no spending at all still count towards the average:
        // a quiet January is information about the year, not an absence of it.
        $observedMonths = max(1, $months);

        $lines = [];

        foreach ($buckets as $bucket) {
            $share = $total > 0 ? $bucket['total_spent'] / $total : 0.0;

            if ($share < self::MIN_SHARE) {
                continue;
            }

            $average = (int) round($bucket['total_spent'] / $observedMonths);

            $lines[] = [
                'scope' => 'category',
                'scope_id' => $bucket['category_id'],
                'name' => $bucket['category'],
                'suggested_amount' => $this->toWholeUnits($average, $currency),
                'average_monthly' => $average,
                'total_spent' => $bucket['total_spent'],
                'transaction_count' => $bucket['transaction_count'],
                'share' => round($share, 4),
            ];
        }

        usort($lines, static fn (array $a, array $b): int => $b['average_monthly'] <=> $a['average_monthly']);

        $overallAverage = (int) round($total / $observedMonths);

        return [
            // Said in the payload, not only in this class's name, because the
            // client renders whatever comes back and a draft must never be
            // mistaken for a saved budget.
            'draft' => true,
            'persisted' => false,
            'needs_confirmation' => true,

            'period' => $target->format('Y-m'),
            'period_type' => 'monthly',
            'starts_at' => $target->toDateString(),
            'ends_at' => $target->endOfMonth()->toDateString(),
            'currency' => $currency->code,

            'overall' => [
                'scope' => 'overall',
                'scope_id' => null,
                'name' => 'overall',
                'suggested_amount' => $this->toWholeUnits($overallAverage, $currency),
                'average_monthly' => $overallAverage,
                'total_spent' => $total,
                'transaction_count' => $rows->count(),
            ],
            'lines' => $lines,

            'based_on' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'months' => $months,
                'months_with_spending' => count($observed),
                'transaction_count' => $rows->count(),
            ],
        ];
    }

    /**
     * Every category mapped to the root of its tree.
     *
     * A budget on "Restaurant" and another on "Groceries" is more admin than
     * anyone wants; one on "Food" is a budget people keep.
     *
     * @return array<string, array{id: string, name: string}>
     */
    private function rootCategories(): array
    {
        $categories = Category::query()->get(['id', 'name', 'path']);
        $byPath = [];

        foreach ($categories as $category) {
            $byPath[(string) $category->path] = ['id' => $category->id, 'name' => (string) $category->name];
        }

        $roots = [];

        foreach ($categories as $category) {
            $segments = array_values(array_filter(explode('/', (string) $category->path)));
            $rootPath = '/'.($segments[0] ?? '');

            $roots[$category->id] = $byPath[$rootPath]
                ?? ['id' => $category->id, 'name' => (string) $category->name];
        }

        return $roots;
    }

    /**
     * Rounds to a whole major unit — 1,847.32 becomes 1,847.00.
     *
     * A ceiling is a decision, and nobody decides to spend twelve lira and
     * thirty-one kuruş on groceries. Currencies with no minor unit are already
     * whole and pass through untouched.
     */
    private function toWholeUnits(int $minorUnits, Currency $currency): int
    {
        $factor = $currency->factor();

        return $factor <= 1 ? $minorUnits : (int) (round($minorUnits / $factor) * $factor);
    }
}
