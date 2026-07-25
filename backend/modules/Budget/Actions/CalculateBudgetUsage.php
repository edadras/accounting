<?php

declare(strict_types=1);

namespace Modules\Budget\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Budget\Models\Budget;
use Modules\Budget\Models\BudgetUsage;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;

/**
 * Derives what a budget has consumed in a period and materializes it.
 *
 * Only expenses count. A transfer moves the user's own money between their own
 * accounts and is not spending; counting it would let someone blow their food
 * budget by topping up their wallet. Income is not spending either, and netting
 * it off would hide overspending behind a payday.
 */
final readonly class CalculateBudgetUsage
{
    public function __construct(private WorkspaceContext $context) {}

    public function handle(Budget $budget, ?\DateTimeInterface $at = null): BudgetUsage
    {
        [$start, $end] = $budget->periodWindow($at);

        $spent = $this->spentBetween($budget, $start, $end);

        $usage = BudgetUsage::query()->firstOrNew([
            'budget_id' => $budget->id,
            'period_key' => $budget->periodKey($at),
        ]);

        $usage->fill([
            'spent_amount' => $spent->minorUnits,
            'currency' => $spent->currency->code,
        ]);

        // Stamped unconditionally: the row records when the figure was last
        // proven against the ledger, not only when the figure happened to move.
        $usage->updated_at = CarbonImmutable::now();
        $usage->save();

        return $usage;
    }

    /** Spend for this budget's scope between two instants, in the workspace base currency. */
    public function spentBetween(Budget $budget, \DateTimeInterface $start, \DateTimeInterface $end): Money
    {
        $baseCurrency = Currency::of($this->context->baseCurrency());

        $query = Transaction::query()
            ->ofType(Transaction::TYPE_EXPENSE)
            ->between($start, $end)

            // base_amount is frozen against the base currency of the day. If the
            // workspace has since switched, those older rows are denominated in
            // something else and summing them would add two different yardsticks.
            ->where('base_currency', $baseCurrency->code);

        if (! $this->constrainToScope($query, $budget)) {
            return Money::zero($baseCurrency);
        }

        return Money::of((int) $query->sum('base_amount'), $baseCurrency);
    }

    /** False when the scope can match nothing, so the caller can skip the query. */
    private function constrainToScope(Builder $query, Budget $budget): bool
    {
        if ($budget->scope === Budget::SCOPE_OVERALL) {
            return true;
        }

        if ($budget->scope === Budget::SCOPE_CATEGORY) {
            $category = $budget->targetCategory();

            if ($category === null) {
                return false;
            }

            // A budget on "Food" has to include Restaurant and Groceries, or
            // every parent category reads as unspent while its children burn.
            $query->whereIn('category_id', $category->descendantIds());

            return true;
        }

        // project / trip / building / member: the modules that own those tables
        // are not built yet, so there is nothing honest to sum. Reporting zero
        // beats inventing a number.
        return false;
    }
}
