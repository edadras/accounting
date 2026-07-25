<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Controllers;

use App\Core\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Buildings\Http\Concerns\PresentsMoney;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingCharge;
use Modules\Buildings\Queries\DebtorsReport;

final class BuildingReportController
{
    use PresentsMoney;

    public function debtors(Request $request, string $building, DebtorsReport $report): JsonResponse
    {
        $model = Building::query()->findOrFail($building);

        // ?period=… is a YYYY-MM string; anything else (an array, an empty
        // value) means "every outstanding month".
        $queried = $request->query('period');
        $period = is_string($queried) && $queried !== '' ? $queried : null;

        $rows = $report->handle($model, $period);

        return response()->json([
            'data' => $rows->map(fn (array $row) => [
                'unit_id' => $row['unit_id'],
                'unit_no' => $row['unit_no'],
                'owner_name' => $row['owner_name'],
                'tenant_name' => $row['tenant_name'],
                'charges_count' => $row['charges_count'],
                'oldest_period' => $row['oldest_period'],
                'owed' => $this->presentMoney($row['owed']),
            ])->all(),
            'meta' => [
                'period' => $period,
                'debtor_count' => $rows->count(),
                'totals' => $report->totals($model, $period)
                    ->map(fn (Money $total) => $this->presentMoney($total))
                    ->all(),
            ],
        ]);
    }

    public function fund(string $building): JsonResponse
    {
        $model = Building::query()->with('fundAccount')->findOrFail($building);
        $balance = $model->fundBalance();

        $charges = fn () => BuildingCharge::query()->where('building_id', $model->id);

        $currency = $balance?->currency->code
            ?? $charges()->value('currency')
            ?? 'IRR';

        $billed = (int) $charges()->sum('amount');
        $collected = (int) $charges()->sum('paid_amount');
        $outstandingBilled = (int) $charges()->outstanding()->sum('amount');
        $outstandingPaid = (int) $charges()->outstanding()->sum('paid_amount');

        return response()->json([
            'data' => [
                'building_id' => $model->id,
                'fund_account_id' => $model->fund_account_id,
                'balance' => $balance === null ? null : $this->presentMoney($balance),
                'billed' => $this->presentMoney(Money::of($billed, $currency)),
                'collected' => $this->presentMoney(Money::of($collected, $currency)),
                'outstanding' => $this->presentMoney(
                    Money::of($outstandingBilled - $outstandingPaid, $currency),
                ),
                'expenses' => $this->presentMoney(
                    Money::of((int) $model->expenses()->sum('amount'), $currency),
                ),
            ],
            'meta' => [
                'unpaid_charges' => $charges()->outstanding()->count(),
            ],
        ]);
    }
}
