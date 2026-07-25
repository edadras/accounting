<?php

declare(strict_types=1);

namespace Modules\Buildings\Queries;

use App\Core\Money\Money;
use Illuminate\Support\Collection;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingCharge;
use Modules\Buildings\Models\BuildingUnit;

/**
 * Who owes the building money, and how much.
 *
 * Aggregated in SQL rather than by loading every charge: a building with years
 * of history has thousands of rows and the manager only ever wants the total
 * per unit.
 */
final readonly class DebtorsReport
{
    /**
     * @param  string|null  $period  restrict to one YYYY-MM month; null means all outstanding months
     * @return Collection<int, array{
     *     unit_id: string,
     *     unit_no: string,
     *     owner_name: string|null,
     *     tenant_name: string|null,
     *     charges_count: int,
     *     oldest_period: string,
     *     owed: Money,
     * }> biggest debtor first
     */
    public function handle(Building $building, ?string $period = null): Collection
    {
        /** @var Collection<int, object> $rows */
        $rows = BuildingCharge::query()
            ->where('building_id', $building->id)
            ->outstanding()
            ->when($period !== null, fn ($query) => $query->forPeriod($period))
            ->selectRaw('unit_id, currency, COUNT(*) as charges_count, MIN(period) as oldest_period, SUM(amount - paid_amount) as owed')
            ->groupBy('unit_id', 'currency')
            ->get();

        $units = BuildingUnit::query()
            ->whereIn('id', $rows->pluck('unit_id')->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (object $row) use ($units): ?array {
                $unit = $units->get($row->unit_id);
                $owed = (int) $row->owed;

                if ($unit === null || $owed <= 0) {
                    return null;
                }

                return [
                    'unit_id' => $unit->id,
                    'unit_no' => $unit->unit_no,
                    'owner_name' => $unit->owner_name,
                    'tenant_name' => $unit->tenant_name,
                    'charges_count' => (int) $row->charges_count,
                    'oldest_period' => (string) $row->oldest_period,
                    'owed' => Money::of($owed, (string) $row->currency),
                ];
            })
            ->filter()
            // Largest debt first; unit number breaks ties so the list is stable
            // between calls and does not reshuffle under the manager's cursor.
            ->sort(fn (array $a, array $b): int => ($b['owed']->minorUnits <=> $a['owed']->minorUnits)
                ?: strnatcmp($a['unit_no'], $b['unit_no']))
            ->values();
    }

    /** Everything the building is owed, per currency. @return Collection<string, Money> */
    public function totals(Building $building, ?string $period = null): Collection
    {
        return $this->handle($building, $period)
            ->groupBy(fn (array $row) => $row['owed']->currency->code)
            ->map(fn (Collection $rows, string $code) => Money::sum(
                $rows->map(fn (array $row) => $row['owed']),
                $code,
            ));
    }
}
