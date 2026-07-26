<?php

declare(strict_types=1);

namespace Modules\Budget\Http\Controllers;

use App\Core\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Budget\Actions\CalculateBudgetUsage;
use Modules\Budget\Actions\RolloverBudget;
use Modules\Budget\Http\Requests\StoreBudgetRequest;
use Modules\Budget\Http\Requests\UpdateBudgetRequest;
use Modules\Budget\Http\Resources\BudgetResource;
use Modules\Budget\Models\Budget;
use Modules\Core\Models\WorkspaceMember;

final class BudgetController
{
    public function index(Request $request): JsonResponse
    {
        $query = Budget::query()
            ->orderBy('name')
            ->orderBy('id');

        if ($scope = $request->query('scope')) {
            $query->where('scope', $scope);
        }

        if ($scopeId = $request->query('scope_id')) {
            $query->where('scope_id', $scopeId);
        }

        if ($period = $request->query('period')) {
            $query->where('period', $period);
        }

        return response()->json([
            'data' => BudgetResource::collection($query->get()),
        ]);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $budget = new Budget;

        if (! empty($data['id'])) {
            // Client-generated ULID: a budget created offline keeps its identity.
            $budget->id = $data['id'];
        }

        if ($data['scope'] === Budget::SCOPE_OVERALL) {
            $data['scope_id'] = null;
        }

        $budget->fill($data);
        $budget->save();

        return (new BudgetResource($budget))->response()->setStatusCode(201);
    }

    public function update(UpdateBudgetRequest $request, string $id): JsonResponse
    {
        $budget = Budget::query()->findOrFail($id);

        $data = $request->validated();

        // Moving to the overall scope drops the target rather than leaving a
        // stale one behind, matching what store() does.
        if (($data['scope'] ?? null) === Budget::SCOPE_OVERALL) {
            $data['scope_id'] = null;
        }

        $budget->fill($data);
        $budget->save();

        return (new BudgetResource($budget))->response();
    }

    public function show(string $id): JsonResponse
    {
        return (new BudgetResource(Budget::query()->findOrFail($id)))->response();
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        Budget::query()->findOrFail($id)->delete();

        return response()->json(status: 204);
    }

    /** Live consumption for every budget in the workspace. */
    public function status(
        Request $request,
        CalculateBudgetUsage $calculate,
        RolloverBudget $rollover,
    ): JsonResponse {
        $at = $request->query('at') ? new \DateTimeImmutable((string) $request->query('at')) : null;

        $budgets = Budget::query()->orderBy('name')->orderBy('id')->get();

        return response()->json([
            'data' => $budgets
                ->map(fn (Budget $budget) => $this->present($budget, $at, $calculate, $rollover))
                ->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(
        Budget $budget,
        ?\DateTimeInterface $at,
        CalculateBudgetUsage $calculate,
        RolloverBudget $rollover,
    ): array {
        [$start, $end] = $budget->periodWindow($at);

        $spent = $calculate->handle($budget, $at)->money();
        $effective = $rollover->handle($budget, $at);
        $remaining = $effective->minus($spent);
        $percentage = $this->percentage($spent, $effective);

        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'scope' => $budget->scope,
            'scope_id' => $budget->scope_id,
            'period' => $budget->period,
            'period_key' => $budget->periodKey($at),
            'period_start' => $start->toIso8601String(),
            'period_end' => $end->toIso8601String(),

            'amount' => $this->money($rollover->baseAmount($budget)),
            'effective_amount' => $this->money($effective),
            'spent' => $this->money($spent),
            'remaining' => $this->money($remaining),
            'percentage' => $percentage,

            'rollover' => $budget->rollover,
            'alert_thresholds' => $budget->alertThresholds(),
            'thresholds_crossed' => array_values(array_filter(
                $budget->alertThresholds(),
                fn (int $threshold): bool => $percentage >= $threshold,
            )),
            'is_over_budget' => $remaining->isNegative(),
        ];
    }

    private function percentage(Money $spent, Money $effective): float
    {
        if ($effective->minorUnits <= 0) {
            // A zero ceiling that has seen any spend is fully consumed; without
            // this the division would either explode or report 0%.
            return $spent->minorUnits > 0 ? 100.0 : 0.0;
        }

        return round($spent->minorUnits * 100 / $effective->minorUnits, 2);
    }

    /** @return array<string, mixed> */
    private function money(Money $money): array
    {
        return [
            'value' => $money->minorUnits,
            'currency' => $money->currency->code,
            'minor_unit' => $money->currency->minorUnit,
            'decimal' => $money->toDecimalString(),
        ];
    }
}
