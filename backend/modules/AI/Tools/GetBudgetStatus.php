<?php

declare(strict_types=1);

namespace Modules\AI\Tools;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Contracts\Tool;
use Modules\AI\Tools\Concerns\ReadsWindow;
use Modules\Budget\Actions\CalculateBudgetUsage;
use Modules\Budget\Models\Budget;

final class GetBudgetStatus implements Tool
{
    use ReadsWindow;

    public function __construct(private readonly CalculateBudgetUsage $usage) {}

    public function name(): string
    {
        return 'get_budget_status';
    }

    public function description(): string
    {
        return 'Every budget for a period with its ceiling, what has been spent against it, and what is left.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => ['type' => 'string', 'description' => 'Any date inside the period of interest, YYYY-MM-DD. Defaults to today.'],
            ],
        ];
    }

    public function run(array $arguments): array
    {
        $at = $this->date($arguments['period'] ?? null) ?? CarbonImmutable::now();

        // The Budget module is optional in a deployment; a chat that 500s
        // because it is absent is worse than one that says there are none.
        if (! Schema::hasTable('budgets')) {
            return ['period' => $at->format('Y-m'), 'budgets' => []];
        }

        $budgets = [];

        foreach (Budget::query()->get() as $budget) {
            [$start, $end] = $budget->periodWindow($at);
            $spent = $this->usage->spentBetween($budget, $start, $end);
            $limit = (int) $budget->amount;

            $budgets[] = [
                'id' => $budget->id,
                'name' => $budget->name,
                'scope' => $budget->scope,
                'period' => $budget->periodKey($at),
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'currency' => $spent->currency->code,
                'limit' => $limit,
                'spent' => $spent->minorUnits,
                'remaining' => $limit - $spent->minorUnits,
                'usage_percent' => $limit > 0 ? (int) round($spent->minorUnits * 100 / $limit) : 0,
            ];
        }

        return ['period' => $at->format('Y-m'), 'budgets' => $budgets];
    }
}
