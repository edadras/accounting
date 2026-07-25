<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Controllers;

use App\Core\Money\Money;
use Illuminate\Http\JsonResponse;
use Modules\Travel\Actions\SettleTrip;
use Modules\Travel\Http\Controllers\Concerns\ResolvesTrip;
use Modules\Travel\Http\Requests\SettleTripRequest;
use Modules\Travel\Http\Resources\MoneyPayload;
use Modules\Travel\Http\Resources\SettlementResource;
use Modules\Travel\Models\Trip;
use Modules\Travel\Support\Transfer;

final class SettlementController
{
    use ResolvesTrip;

    /** What each member stands at, and the payments that would close the trip. */
    public function preview(string $tripId, SettleTrip $settle): JsonResponse
    {
        $trip = $this->trip($tripId);

        return response()->json([
            'data' => [
                'balances' => $this->balances($trip, $settle),
                'transfers' => array_map(
                    fn (Transfer $transfer) => $this->presentTransfer($transfer),
                    $settle->preview($trip),
                ),
            ],
        ]);
    }

    public function settle(SettleTripRequest $request, string $tripId, SettleTrip $settle): JsonResponse
    {
        $trip = $this->trip($tripId);
        $settledAt = $request->validated('settled_at');

        $settlements = $settle->settle(
            $trip,
            $settledAt === null ? null : new \DateTimeImmutable((string) $settledAt),
        );

        return response()->json([
            'data' => SettlementResource::collection(collect($settlements)),
        ], 201);
    }

    /** @return list<array<string, mixed>> */
    private function balances(Trip $trip, SettleTrip $settle): array
    {
        $names = $trip->members()->pluck('display_name', 'id');
        $rows = [];

        foreach ($settle->balances($trip) as $memberId => $net) {
            $rows[] = [
                'member_id' => $memberId,
                'display_name' => $names[$memberId] ?? null,
                'balance' => MoneyPayload::from(Money::of($net, $trip->base_currency)),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function presentTransfer(Transfer $transfer): array
    {
        return [
            'from_member_id' => $transfer->fromMemberId,
            'to_member_id' => $transfer->toMemberId,
            'amount' => MoneyPayload::from($transfer->amount),
        ];
    }
}
