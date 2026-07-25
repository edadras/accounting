<?php

declare(strict_types=1);

namespace Modules\Alerts\Scanners;

use Carbon\CarbonImmutable;
use Modules\Alerts\Contracts\AlertScanner;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Support\AlertCandidate;
use Modules\Budget\Actions\CalculateBudgetUsage;
use Modules\Budget\Models\Budget;

/**
 * Budgets that have crossed one of their own alert thresholds this period.
 *
 * The dedupe key names the period and the threshold, so a budget that goes past
 * 80% and later past 100% produces two alerts in total however many times the
 * scan runs, and starts again cleanly next month.
 */
final readonly class BudgetThresholdScanner implements AlertScanner
{
    public function __construct(private CalculateBudgetUsage $usage) {}

    public function type(): string
    {
        return AlertRule::TYPE_BUDGET_THRESHOLD;
    }

    public function scan(AlertRule $rule, CarbonImmutable $now): iterable
    {
        $only = $rule->setting('budget_ids');

        $query = Budget::query();

        if (is_array($only) && $only !== []) {
            $query->whereIn('id', $only);
        }

        foreach ($query->get() as $budget) {
            yield from $this->candidatesFor($budget, $now);
        }
    }

    /** @return iterable<AlertCandidate> */
    private function candidatesFor(Budget $budget, CarbonImmutable $now): iterable
    {
        if ($budget->amount <= 0) {
            return;
        }

        [$start, $end] = $budget->periodWindow($now);
        $spent = $this->usage->spentBetween($budget, $start, $end);

        // Spending is summed in the workspace base currency; a budget written in
        // another one is not comparable without a rate, and inventing one to
        // fire an alert would be worse than staying quiet.
        if (! $spent->currency->equals($budget->money()->currency)) {
            return;
        }

        $percent = intdiv($spent->minorUnits * 100, $budget->amount);
        $periodKey = $budget->periodKey($now);

        foreach ($budget->alertThresholds() as $threshold) {
            if ($percent < $threshold) {
                continue;
            }

            yield new AlertCandidate(
                type: $this->type(),
                dedupeKey: "budget:{$budget->id}:{$periodKey}:threshold:{$threshold}",
                payload: [
                    'budget_id' => $budget->id,
                    'budget_name' => $budget->name,
                    'period_key' => $periodKey,
                    'threshold' => $threshold,
                    'percent' => $percent,
                    'spent' => $spent,
                    'limit' => $budget->money(),
                ],
                scheduledAt: $now,
            );
        }
    }
}
