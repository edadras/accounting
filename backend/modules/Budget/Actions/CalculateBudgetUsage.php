<?php

declare(strict_types=1);

namespace Modules\Budget\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Modules\Budget\Models\Budget;
use Modules\Budget\Models\BudgetUsage;
use Modules\Buildings\Models\BuildingExpense;
use Modules\Business\Models\Project;
use Modules\Core\Support\WorkspaceContext;
use Modules\Family\Models\FamilyMember;
use Modules\Ledger\Models\Transaction;
use Modules\Travel\Models\SplitExpense;
use Modules\Travel\Models\Trip;

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
    /**
     * The scopes `Budget::SCOPES` allows but the model does not name
     * individually. Spelt out here so the matching below reads as intent rather
     * than as loose strings.
     */
    private const SCOPE_PROJECT = 'project';

    private const SCOPE_TRIP = 'trip';

    private const SCOPE_BUILDING = 'building';

    private const SCOPE_MEMBER = 'member';

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
        $usage->updated_at = Carbon::now();
        $usage->save();

        return $usage;
    }

    /** Spend for this budget's scope between two instants, in the workspace base currency. */
    public function spentBetween(Budget $budget, \DateTimeInterface $start, \DateTimeInterface $end): Money
    {
        $baseCurrency = Currency::of($this->context->baseCurrency());

        if ($budget->scope === self::SCOPE_TRIP) {
            // A trip's spending lives in split_expenses, not in the ledger — the
            // people on a trip mostly pay each other, and only a settlement ever
            // reaches an account. It therefore cannot be expressed as a
            // constraint on the transaction query below.
            return $this->tripSpend($budget, $start, $end, $baseCurrency);
        }

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

    /**
     * False when the scope can match nothing, so the caller can skip the query.
     *
     * @param  Builder<Transaction>  $query
     */
    private function constrainToScope(Builder $query, Budget $budget): bool
    {
        if ($budget->scope === Budget::SCOPE_OVERALL) {
            return true;
        }

        // Every remaining scope points at something. A budget scoped to nothing
        // in particular can only mean a half-written row, and totalling the
        // whole ledger for it would be worse than reporting zero.
        if ($budget->scope_id === null) {
            return false;
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

        return match ($budget->scope) {
            // Project and member attribution both ride on the transaction's
            // tags, which is how a module points at a posting without the
            // Ledger table growing a column per module.
            self::SCOPE_PROJECT => $this->tagged($query, Project::tagFor($budget->scope_id)),
            self::SCOPE_MEMBER => $this->tagged($query, FamilyMember::tagFor($budget->scope_id)),

            // A building spends out of its fund through a real ledger posting,
            // and building_expenses already records which one.
            self::SCOPE_BUILDING => $this->buildingExpenses($query, $budget->scope_id),

            default => false,
        };
    }

    /** @param  Builder<Transaction>  $query */
    private function tagged(Builder $query, string $tag): bool
    {
        $query->whereJsonContains('tags', $tag);

        return true;
    }

    /** @param  Builder<Transaction>  $query */
    private function buildingExpenses(Builder $query, string $buildingId): bool
    {
        $query->whereIn('id', BuildingExpense::query()
            ->where('building_id', $buildingId)
            ->whereNotNull('transaction_id')
            ->select('transaction_id'));

        return true;
    }

    /**
     * What a trip consumed in the window.
     *
     * Split expenses carry their own frozen base amount, denominated in the
     * trip's base currency rather than the workspace's. Where those differ there
     * is no rate on the row to convert with, and inventing today's rate would
     * rewrite a settlement people have already paid — so such a trip reports
     * zero rather than a number that cannot be justified.
     */
    private function tripSpend(Budget $budget, \DateTimeInterface $start, \DateTimeInterface $end, Currency $baseCurrency): Money
    {
        if ($budget->scope_id === null) {
            return Money::zero($baseCurrency);
        }

        $trip = Trip::query()->find($budget->scope_id);

        if ($trip === null || ! Currency::of($trip->base_currency)->equals($baseCurrency)) {
            return Money::zero($baseCurrency);
        }

        $total = SplitExpense::query()
            ->where('trip_id', $trip->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->sum('base_amount');

        return Money::of((int) $total, $baseCurrency);
    }
}
