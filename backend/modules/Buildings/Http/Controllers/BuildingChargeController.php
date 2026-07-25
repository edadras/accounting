<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Controllers;

use App\Core\Money\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Buildings\Actions\IssuePeriodicCharges;
use Modules\Buildings\Actions\RecordChargePayment;
use Modules\Buildings\Http\Concerns\PresentsMoney;
use Modules\Buildings\Http\Requests\IssueChargesRequest;
use Modules\Buildings\Http\Requests\PayChargeRequest;
use Modules\Buildings\Http\Resources\BuildingChargeResource;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingCharge;

final class BuildingChargeController
{
    use PresentsMoney;

    public function index(Request $request, string $building): JsonResponse
    {
        $model = Building::query()->findOrFail($building);

        $query = $model->charges()->with('unit');

        if ($period = $request->query('period')) {
            $query->forPeriod((string) $period);
        }

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }

        if ($unitId = $request->query('unit_id')) {
            $query->where('unit_id', (string) $unitId);
        }

        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }

        $charges = $query->orderByDesc('period')->orderBy('unit_id')->get();

        $currency = $charges->first()?->currency;

        return response()->json([
            'data' => BuildingChargeResource::collection($charges),
            'meta' => [
                'count' => $charges->count(),
                'billed' => $currency === null ? null : $this->presentMoney(
                    Money::of((int) $charges->sum('amount'), $currency),
                ),
                'collected' => $currency === null ? null : $this->presentMoney(
                    Money::of((int) $charges->sum('paid_amount'), $currency),
                ),
            ],
        ]);
    }

    /** Issues the whole building's charges for one month in a single call. */
    public function issue(IssueChargesRequest $request, string $building, IssuePeriodicCharges $issue): JsonResponse
    {
        $model = Building::query()->findOrFail($building);
        $data = $request->validated();

        $before = $model->charges()->forPeriod($data['period'])->count();

        $charges = $issue->handle(
            building: $model,
            period: $data['period'],
            total: Money::of((int) $data['total'], $data['currency']),
            dueDate: isset($data['due_date']) ? new \DateTimeImmutable($data['due_date']) : null,
        );

        $issued = $charges->sum(fn (BuildingCharge $charge) => $charge->amount);

        return response()->json([
            'data' => BuildingChargeResource::collection($charges),
            'meta' => [
                'period' => $data['period'],
                'count' => $charges->count(),
                'already_issued' => $before > 0,
                'total' => $this->presentMoney(Money::of((int) $issued, $data['currency'])),
            ],
        ], $before > 0 ? 200 : 201);
    }

    public function pay(PayChargeRequest $request, string $charge, RecordChargePayment $record): JsonResponse
    {
        $model = BuildingCharge::query()->findOrFail($charge);
        $data = $request->validated();

        $updated = $record->handle(
            charge: $model,
            amount: Money::of((int) $data['amount'], $data['currency']),
            paidAt: isset($data['paid_at']) ? new \DateTimeImmutable($data['paid_at']) : null,
            reference: $data['reference'] ?? null,
            idempotencyKey: $request->header('Idempotency-Key') ?? ($data['idempotency_key'] ?? null),
        );

        return (new BuildingChargeResource($updated->load('unit')))->response();
    }
}
