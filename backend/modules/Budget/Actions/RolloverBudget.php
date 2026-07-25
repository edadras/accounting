<?php

declare(strict_types=1);

namespace Modules\Budget\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Budget\Models\Budget;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\ExchangeRateResolver;

/**
 * Works out what a budget may actually spend this period once last period's
 * leftovers are carried in.
 *
 * The stored `amount` is never touched: it is what the user set, and a report
 * has to be able to show the allowance and the carry-over as two separate
 * facts rather than one number that quietly grew.
 */
final readonly class RolloverBudget
{
    public function __construct(
        private CalculateBudgetUsage $usage,
        private WorkspaceContext $context,
        private ExchangeRateResolver $rates,
    ) {}

    public function handle(Budget $budget, ?\DateTimeInterface $at = null): Money
    {
        $amount = $this->baseAmount($budget);

        if (! $budget->rollover) {
            return $amount;
        }

        $previous = $budget->previousPeriodWindow($at);

        if ($previous === null) {
            return $amount;
        }

        $remainder = $amount->minus($this->usage->spentBetween($budget, $previous[0], $previous[1]));

        // An overspent period does not hand the next one a debt. The overspend
        // already showed up as an over-100% period of its own; subtracting it
        // again here would charge the user for it twice.
        return $remainder->isPositive() ? $amount->plus($remainder) : $amount;
    }

    /**
     * The budget's own amount expressed in the workspace base currency.
     *
     * Spend is only ever summed in the base currency, so the ceiling has to be
     * quoted there too before the two can be compared.
     */
    public function baseAmount(Budget $budget): Money
    {
        $base = Currency::of($this->context->baseCurrency());
        $amount = $budget->money();

        return $amount->convertTo($base, $this->rates->rate($amount->currency, $base));
    }
}
