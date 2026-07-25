<?php

declare(strict_types=1);

namespace Modules\Travel\Actions;

use App\Core\Money\Money;
use Illuminate\Support\Facades\DB;
use Modules\Travel\Exceptions\TravelException;
use Modules\Travel\Models\Settlement;
use Modules\Travel\Models\SplitExpense;
use Modules\Travel\Models\SplitShare;
use Modules\Travel\Models\Trip;
use Modules\Travel\Support\Transfer;

/**
 * Works out who pays whom at the end of a trip, in as few payments as possible.
 *
 * Everyone's net position (paid − owed, adjusted by whatever has already been
 * settled) is computed in the trip's base currency, and then the biggest
 * creditor is repeatedly paired with the biggest debtor. Each pairing zeroes at
 * least one of the two, so n members need at most n − 1 payments — which is the
 * difference between "six people make five bank transfers" and "six people make
 * fifteen".
 */
final readonly class SettleTrip
{
    /**
     * Net position per member in the trip's base currency, positive when the
     * member is owed money.
     *
     * @return array<string, int>
     */
    public function balances(Trip $trip): array
    {
        $balances = [];

        foreach ($this->memberIds($trip) as $memberId) {
            $balances[$memberId] = 0;
        }

        $expenseIds = SplitExpense::query()->where('trip_id', $trip->id)->select('id');

        $paid = SplitExpense::query()
            ->where('trip_id', $trip->id)
            ->selectRaw('payer_member_id, SUM(base_amount) AS total')
            ->groupBy('payer_member_id')
            ->pluck('total', 'payer_member_id');

        foreach ($paid as $memberId => $total) {
            $memberId = (string) $memberId;
            $balances[$memberId] = ($balances[$memberId] ?? 0) + (int) $total;
        }

        $owed = SplitShare::query()
            ->whereIn('split_expense_id', $expenseIds)
            ->selectRaw('member_id, SUM(base_share_amount) AS total')
            ->groupBy('member_id')
            ->pluck('total', 'member_id');

        foreach ($owed as $memberId => $total) {
            $memberId = (string) $memberId;
            $balances[$memberId] = ($balances[$memberId] ?? 0) - (int) $total;
        }

        // A settlement already paid moves the two members towards zero, so a
        // second preview of the same trip suggests nothing.
        $settlements = Settlement::query()->where('trip_id', $trip->id)->get();

        foreach ($settlements as $settlement) {
            $balances[$settlement->from_member_id] = ($balances[$settlement->from_member_id] ?? 0) + $settlement->amount;
            $balances[$settlement->to_member_id] = ($balances[$settlement->to_member_id] ?? 0) - $settlement->amount;
        }

        return $balances;
    }

    /**
     * The payments that would settle the trip, without writing anything.
     *
     * @return list<Transfer>
     */
    public function preview(Trip $trip): array
    {
        $balances = $this->balances($trip);
        $difference = array_sum($balances);

        if ($difference !== 0) {
            // Shares always sum to their expense, so this cannot happen through
            // the API. If it ever does, the books are wrong and inventing a
            // settlement would only hide it.
            throw TravelException::unbalancedTrip($trip->id, $difference);
        }

        $currency = $trip->baseCurrency();
        $creditors = [];
        $debtors = [];

        foreach ($balances as $memberId => $net) {
            if ($net > 0) {
                $creditors[] = [$memberId, $net];
            } elseif ($net < 0) {
                // Members who neither paid nor owe anything are simply not
                // here, so they appear in no transfer.
                $debtors[] = [$memberId, -$net];
            }
        }

        $largestFirst = static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]);
        usort($creditors, $largestFirst);
        usort($debtors, $largestFirst);

        $transfers = [];
        $creditor = 0;
        $debtor = 0;

        while ($creditor < count($creditors) && $debtor < count($debtors)) {
            $amount = min($creditors[$creditor][1], $debtors[$debtor][1]);

            $transfers[] = new Transfer(
                fromMemberId: $debtors[$debtor][0],
                toMemberId: $creditors[$creditor][0],
                amount: Money::of($amount, $currency),
            );

            $creditors[$creditor][1] -= $amount;
            $debtors[$debtor][1] -= $amount;

            if ($creditors[$creditor][1] === 0) {
                $creditor++;
            }

            if ($debtors[$debtor][1] === 0) {
                $debtor++;
            }
        }

        return $transfers;
    }

    /**
     * Records the suggested payments as settlements.
     *
     * @return list<Settlement>
     */
    public function settle(Trip $trip, ?\DateTimeInterface $settledAt = null): array
    {
        $transfers = $this->preview($trip);
        $settledAt ??= now();

        return DB::transaction(function () use ($trip, $transfers, $settledAt): array {
            $settlements = [];

            foreach ($transfers as $transfer) {
                $settlements[] = Settlement::query()->create([
                    'trip_id' => $trip->id,
                    'from_member_id' => $transfer->fromMemberId,
                    'to_member_id' => $transfer->toMemberId,
                    'amount' => $transfer->amount->minorUnits,
                    'currency' => $transfer->amount->currency->code,
                    'settled_at' => $settledAt,
                ]);
            }

            return $settlements;
        });
    }

    /** @return list<string> */
    private function memberIds(Trip $trip): array
    {
        return array_values(
            $trip->members()
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id')
                ->map(strval(...))
                ->all()
        );
    }
}
