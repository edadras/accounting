<?php

declare(strict_types=1);

namespace Modules\Buildings\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Buildings\Exceptions\BuildingsException;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingCharge;
use Modules\Buildings\Models\BuildingUnit;

/**
 * Issues one charge per unit for a month, splitting a single total across the
 * building by its charge formula.
 *
 * The invariant this class exists to hold: **the issued charges sum to exactly
 * the total**. Rounding each unit's share on its own leaves a remainder that
 * belongs to nobody, and a fund that is a few lira short every month is a fund
 * nobody can reconcile. Money::allocateByWeights hands that remainder out
 * instead of dropping it.
 */
final readonly class IssuePeriodicCharges
{
    private const PERIOD_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * @return Collection<int, BuildingCharge> the period's charges, oldest first
     */
    public function handle(
        Building $building,
        string $period,
        Money $total,
        ?\DateTimeInterface $dueDate = null,
    ): Collection {
        if (preg_match(self::PERIOD_PATTERN, $period) !== 1) {
            throw BuildingsException::invalidPeriod($period);
        }

        if (! $total->isPositive()) {
            throw BuildingsException::nonPositiveAmount();
        }

        if (! in_array($building->charge_formula, Building::FORMULAS, true)) {
            throw BuildingsException::unknownChargeFormula((string) $building->charge_formula);
        }

        $this->assertFundCurrencyMatches($building, $total->currency);

        // The unique index on (unit_id, period) is the real guarantee, but it
        // would surface as a driver error halfway through the loop, leaving
        // some units billed and some not. Checking first makes re-issuing a
        // no-op that returns what is already there.
        $existing = $building->charges()->forPeriod($period)->orderBy('id')->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $units = $building->units()->orderBy('unit_no')->orderBy('id')->get();

        if ($units->isEmpty()) {
            throw BuildingsException::buildingHasNoUnits($building->id);
        }

        $weights = $this->weightsFor($building, $units);

        if (array_sum($weights) <= 0) {
            throw BuildingsException::zeroWeightTotal((string) $building->charge_formula);
        }

        $shares = $total->allocateByWeights($weights);

        return DB::transaction(function () use ($building, $units, $shares, $period, $dueDate, $total): Collection {
            $charges = new Collection;

            foreach ($units as $index => $unit) {
                $charges->push(BuildingCharge::query()->create([
                    'building_id' => $building->id,
                    'unit_id' => $unit->id,
                    'period' => $period,
                    'amount' => $shares[$index]->minorUnits,
                    'currency' => $total->currency->code,
                    'due_date' => $dueDate,
                    'status' => BuildingCharge::STATUS_UNPAID,
                    'paid_amount' => 0,
                ]));
            }

            return $charges;
        });
    }

    /**
     * The integer weight each unit's share is proportional to.
     *
     * @param  Collection<int, BuildingUnit>  $units
     * @return list<int>
     */
    private function weightsFor(Building $building, Collection $units): array
    {
        return $units->map(fn (BuildingUnit $unit): int => match ($building->charge_formula) {
            Building::FORMULA_FIXED => 1,
            Building::FORMULA_PER_AREA => $unit->areaWeight(),
            Building::FORMULA_PER_RESIDENT => $unit->residents_count,
            Building::FORMULA_MIXED => $unit->shareWeight(),
        })->values()->all();
    }

    private function assertFundCurrencyMatches(Building $building, Currency $currency): void
    {
        $fund = $building->fundAccount()->first();

        if ($fund === null) {
            return;
        }

        // Caught here rather than at payment time: discovering the mismatch
        // after forty bills went out means forty corrections.
        if (! $currency->equals(Currency::of($fund->currency))) {
            throw BuildingsException::currencyMismatch($currency->code, $fund->currency);
        }
    }
}
