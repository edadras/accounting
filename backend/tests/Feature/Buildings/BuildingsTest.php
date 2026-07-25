<?php

declare(strict_types=1);

namespace Tests\Feature\Buildings;

use App\Core\Money\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Modules\Buildings\Actions\IssuePeriodicCharges;
use Modules\Buildings\Actions\RecordChargePayment;
use Modules\Buildings\Exceptions\BuildingsException;
use Modules\Buildings\Models\Building;
use Modules\Buildings\Models\BuildingCharge;
use Modules\Buildings\Models\BuildingUnit;
use Modules\Buildings\Queries\DebtorsReport;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Models\Account;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The buildings invariants.
 *
 * The one that matters most: a period's issued charges sum to exactly the total
 * the manager asked for. A fund that is short by the rounding remainder every
 * month cannot be reconciled against the bank, and the residents' arithmetic
 * will not match the manager's.
 */
final class BuildingsTest extends LedgerTestCase
{
    use RefreshDatabase;

    /** Areas that do not divide cleanly, repeated across the building. */
    private const AWKWARD_AREAS = ['73.5', '81.25', '64.0', '97.33', '55.7'];

    #[Test]
    public function per_area_charges_for_forty_units_sum_to_exactly_the_total(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_PER_AREA);

        $this->inWorkspace($workspace, function () use ($building): void {
            foreach (range(1, 40) as $i) {
                $this->makeUnit($building, (string) (100 + $i), [
                    'area_m2' => self::AWKWARD_AREAS[$i % count(self::AWKWARD_AREAS)],
                ]);
            }
        });

        // 12,345,679 minor units across 40 units of unequal area: nothing about
        // this divides evenly.
        $total = Money::of(12_345_679, 'TRY');

        $charges = $this->inWorkspace(
            $workspace,
            fn () => app(IssuePeriodicCharges::class)->handle($building, '2026-07', $total)
        );

        $this->assertCount(40, $charges);
        $this->assertSame(
            $total->minorUnits,
            (int) $charges->sum('amount'),
            'The issued charges must sum to exactly the building total.',
        );

        // And the database agrees with what the action returned.
        $this->assertSame(
            $total->minorUnits,
            $this->inWorkspace($workspace, fn () => (int) BuildingCharge::query()->forPeriod('2026-07')->sum('amount')),
        );

        // Every unit was billed something, and a bigger flat pays more than a
        // smaller one.
        $byUnitNo = $this->inWorkspace($workspace, fn () => BuildingCharge::query()
            ->with('unit')
            ->forPeriod('2026-07')
            ->get()
            ->keyBy(function (BuildingCharge $charge) {
                $unit = $charge->unit;
                $this->assertNotNull($unit);

                return $unit->unit_no;
            }));

        foreach ($byUnitNo as $charge) {
            $this->assertGreaterThan(0, $charge->amount);
        }

        $areaWeight = function (BuildingCharge $charge) {
            $unit = $charge->unit;
            $this->assertNotNull($unit);

            return $unit->areaWeight();
        };

        $biggest = $byUnitNo->sortByDesc($areaWeight)->firstOrFail();
        $smallest = $byUnitNo->sortBy($areaWeight)->firstOrFail();

        $this->assertGreaterThan($smallest->amount, $biggest->amount);
    }

    #[Test]
    public function per_resident_charges_sum_to_exactly_the_total(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_PER_RESIDENT);

        $this->inWorkspace($workspace, function () use ($building): void {
            foreach (range(1, 17) as $i) {
                $this->makeUnit($building, (string) (200 + $i), [
                    'residents_count' => ($i % 5) + 1,
                ]);
            }
        });

        $total = Money::of(1_000_003, 'TRY');

        $charges = $this->inWorkspace(
            $workspace,
            fn () => app(IssuePeriodicCharges::class)->handle($building, '2026-08', $total)
        );

        $this->assertCount(17, $charges);
        $this->assertSame($total->minorUnits, (int) $charges->sum('amount'));
    }

    #[Test]
    public function fixed_charges_sum_to_exactly_the_total_even_when_it_does_not_divide(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED);

        $this->inWorkspace($workspace, function () use ($building): void {
            foreach (range(1, 7) as $i) {
                $this->makeUnit($building, (string) (300 + $i));
            }
        });

        // 100 across 7 units: 14.28... each, so somebody has to absorb the odd
        // unit rather than it vanishing.
        $total = Money::of(100, 'TRY');

        $charges = $this->inWorkspace(
            $workspace,
            fn () => app(IssuePeriodicCharges::class)->handle($building, '2026-09', $total)
        );

        $this->assertSame(100, (int) $charges->sum('amount'));

        /** @var non-empty-list<int> $amounts every unit in the building got a row */
        $amounts = $charges->pluck('amount')->all();
        $this->assertLessThanOrEqual(1, max($amounts) - min($amounts), 'A fixed charge must be equal to within one minor unit.');
    }

    #[Test]
    public function mixed_charges_split_by_share_factor_and_sum_to_exactly_the_total(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_MIXED);

        $this->inWorkspace($workspace, function () use ($building): void {
            foreach (['1.0', '1.5', '0.75', '2.25', '1.125'] as $i => $factor) {
                $this->makeUnit($building, (string) (400 + $i), ['share_factor' => $factor]);
            }
        });

        $total = Money::of(999_997, 'TRY');

        $charges = $this->inWorkspace(
            $workspace,
            fn () => app(IssuePeriodicCharges::class)->handle($building, '2026-10', $total)
        );

        $this->assertSame($total->minorUnits, (int) $charges->sum('amount'));

        // 2.25 is three times 0.75, so its bill is roughly triple — within the
        // one-unit slack the remainder distribution can introduce.
        $byFactor = $this->inWorkspace($workspace, fn () => BuildingCharge::query()
            ->with('unit')
            ->forPeriod('2026-10')
            ->get()
            ->keyBy(function (BuildingCharge $charge) {
                $unit = $charge->unit;
                $this->assertNotNull($unit);

                return (string) $unit->share_factor;
            }));

        $this->assertEqualsWithDelta(
            $this->chargeFor($byFactor, '0.7500')->amount * 3,
            $this->chargeFor($byFactor, '2.2500')->amount,
            3.0,
        );
    }

    #[Test]
    public function reissuing_the_same_period_creates_no_duplicates(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_PER_AREA);

        $this->inWorkspace($workspace, function () use ($building): void {
            foreach (range(1, 12) as $i) {
                $this->makeUnit($building, (string) (500 + $i), [
                    'area_m2' => self::AWKWARD_AREAS[$i % count(self::AWKWARD_AREAS)],
                ]);
            }
        });

        $total = Money::of(4_567_891, 'TRY');

        $first = $this->inWorkspace(
            $workspace,
            fn () => app(IssuePeriodicCharges::class)->handle($building, '2026-07', $total)
        );

        $second = $this->inWorkspace(
            $workspace,
            fn () => app(IssuePeriodicCharges::class)->handle($building, '2026-07', $total)
        );

        $this->assertCount(12, $first);
        $this->assertCount(12, $second);
        $this->assertSame(
            12,
            $this->inWorkspace($workspace, fn () => BuildingCharge::query()->forPeriod('2026-07')->count()),
        );
        $this->assertSame($first->pluck('id')->sort()->values()->all(), $second->pluck('id')->sort()->values()->all());
        $this->assertSame($total->minorUnits, (int) $second->sum('amount'));
    }

    #[Test]
    public function the_database_refuses_a_second_charge_for_the_same_unit_and_period(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED);
        $unit = $this->inWorkspace($workspace, fn () => $this->makeUnit($building, '601'));

        $this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(50_000, 'TRY')));

        $this->expectException(QueryException::class);

        $this->inWorkspace($workspace, fn () => BuildingCharge::query()->create([
            'building_id' => $building->id,
            'unit_id' => $unit->id,
            'period' => '2026-07',
            'amount' => 1,
            'currency' => 'TRY',
        ]));
    }

    #[Test]
    public function paying_a_charge_moves_exactly_that_amount_into_the_fund(): void
    {
        [$workspace, $building, $fund] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true);
        $this->assertNotNull($fund);

        $this->inWorkspace($workspace, function () use ($building): void {
            foreach (range(1, 4) as $i) {
                $this->makeUnit($building, (string) (700 + $i));
            }
        });

        $charges = $this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(400_000, 'TRY')));

        $charge = $this->onlyCharge($charges);
        $this->assertSame(100_000, $charge->amount);
        $this->assertSame(0, $fund->refresh()->current_balance);

        $paid = $this->inWorkspace($workspace, fn () => app(RecordChargePayment::class)
            ->handle($charge, Money::of(100_000, 'TRY')));

        $this->assertSame(BuildingCharge::STATUS_PAID, $paid->status);
        $this->assertSame(100_000, $paid->paid_amount);
        $this->assertSame(0, $paid->remainingMoney()->minorUnits);
        $this->assertNotNull($paid->transaction_id);

        $this->assertSame(
            100_000,
            $fund->refresh()->current_balance,
            'The fund must grow by exactly the amount paid.',
        );

        // And the ledger agrees: the cached balance still equals its entries.
        $this->assertSame(
            100_000,
            $this->inWorkspace($workspace, fn () => $fund->refresh()->recalculateBalance()->minorUnits),
        );
    }

    #[Test]
    public function a_partial_payment_sets_the_status_and_the_remaining_amount(): void
    {
        [$workspace, $building, $fund] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true);
        $this->assertNotNull($fund);
        $this->inWorkspace($workspace, fn () => $this->makeUnit($building, '801'));

        $charges = $this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(250_000, 'TRY')));

        $charge = $this->onlyCharge($charges);

        $partial = $this->inWorkspace($workspace, fn () => app(RecordChargePayment::class)
            ->handle($charge, Money::of(90_000, 'TRY')));

        $this->assertSame(BuildingCharge::STATUS_PARTIAL, $partial->status);
        $this->assertSame(90_000, $partial->paid_amount);
        $this->assertSame(160_000, $partial->remainingMoney()->minorUnits);
        $this->assertSame(90_000, $fund->refresh()->current_balance);

        // The rest clears it, and the two instalments together equal the bill.
        $settled = $this->inWorkspace($workspace, fn () => app(RecordChargePayment::class)
            ->handle($partial, Money::of(160_000, 'TRY')));

        $this->assertSame(BuildingCharge::STATUS_PAID, $settled->status);
        $this->assertSame(0, $settled->remainingMoney()->minorUnits);
        $this->assertSame(250_000, $fund->refresh()->current_balance);
    }

    #[Test]
    public function overpayment_is_refused(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true);
        $this->inWorkspace($workspace, fn () => $this->makeUnit($building, '901'));

        $charge = $this->onlyCharge($this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(120_000, 'TRY'))));

        try {
            $this->inWorkspace($workspace, fn () => app(RecordChargePayment::class)
                ->handle($charge, Money::of(120_001, 'TRY')));

            $this->fail('Overpaying a charge must be refused.');
        } catch (BuildingsException $e) {
            $this->assertSame('overpayment_refused', $e->errorCode);
        }

        $this->assertSame(0, $charge->refresh()->paid_amount);
        $this->assertSame(BuildingCharge::STATUS_UNPAID, $charge->refresh()->status);
    }

    #[Test]
    public function overpayment_is_refused_after_a_partial_payment_too(): void
    {
        [$workspace, $building, $fund] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true);
        $this->assertNotNull($fund);
        $this->inWorkspace($workspace, fn () => $this->makeUnit($building, '902'));

        $charge = $this->onlyCharge($this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(100_000, 'TRY'))));

        $charge = $this->inWorkspace($workspace, fn () => app(RecordChargePayment::class)
            ->handle($charge, Money::of(60_000, 'TRY')));

        $this->expectException(BuildingsException::class);

        try {
            $this->inWorkspace($workspace, fn () => app(RecordChargePayment::class)
                ->handle($charge, Money::of(40_001, 'TRY')));
        } finally {
            // Nothing leaked into the fund on the refused attempt.
            $this->assertSame(60_000, $fund->refresh()->current_balance);
        }
    }

    #[Test]
    public function the_debtors_report_lists_exactly_the_unpaid_and_partial_units_biggest_first(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_PER_AREA, withFund: true);

        // Four units with deliberately different areas, so their bills differ
        // and the ordering of the report is unambiguous.
        $this->inWorkspace($workspace, function () use ($building): void {
            $this->makeUnit($building, 'A', ['area_m2' => '40.00']);
            $this->makeUnit($building, 'B', ['area_m2' => '30.00']);
            $this->makeUnit($building, 'C', ['area_m2' => '20.00']);
            $this->makeUnit($building, 'D', ['area_m2' => '10.00']);
        });

        $charges = $this->inWorkspace($workspace, function () use ($building) {
            app(IssuePeriodicCharges::class)->handle($building, '2026-07', Money::of(1_000_000, 'TRY'));

            return BuildingCharge::query()
                ->with('unit')
                ->forPeriod('2026-07')
                ->get()
                ->keyBy(function (BuildingCharge $charge) {
                    $unit = $charge->unit;
                    $this->assertNotNull($unit);

                    return $unit->unit_no;
                });
        });

        // A pays in full and drops off the list. B pays part of its bill. C and
        // D pay nothing.
        $this->inWorkspace($workspace, function () use ($charges): void {
            $pay = app(RecordChargePayment::class);
            $a = $this->chargeFor($charges, 'A');
            $pay->handle($a, Money::of($a->amount, 'TRY'));
            $pay->handle($this->chargeFor($charges, 'B'), Money::of(150_000, 'TRY'));
        });

        $rows = $this->inWorkspace($workspace, fn () => app(DebtorsReport::class)->handle($building));

        $this->assertSame(['C', 'B', 'D'], $rows->pluck('unit_no')->all());

        $expected = [
            'C' => $this->chargeFor($charges, 'C')->amount,
            'B' => $this->chargeFor($charges, 'B')->amount - 150_000,
            'D' => $this->chargeFor($charges, 'D')->amount,
        ];

        foreach ($rows as $row) {
            $this->assertSame($expected[$row['unit_no']], $row['owed']->minorUnits);
            $this->assertSame('TRY', $row['owed']->currency->code);
            $this->assertSame(1, $row['charges_count']);
        }

        // Sorted descending, so each row owes at least as much as the next.
        $owed = $rows->map(fn (array $row) => $row['owed']->minorUnits)->all();
        $sorted = $owed;
        rsort($sorted);
        $this->assertSame($sorted, $owed);

        $totals = $this->inWorkspace($workspace, fn () => app(DebtorsReport::class)->totals($building));

        $owedInLira = $totals->get('TRY');
        $this->assertNotNull($owedInLira, 'The lira debts must be totalled under their own currency.');
        $this->assertSame(array_sum($expected), $owedInLira->minorUnits);
    }

    #[Test]
    public function the_debtors_report_totals_several_months_per_unit(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true);

        $this->inWorkspace($workspace, function () use ($building): void {
            $this->makeUnit($building, 'X');
            $this->makeUnit($building, 'Y');
        });

        $this->inWorkspace($workspace, function () use ($building): void {
            $issue = app(IssuePeriodicCharges::class);
            $issue->handle($building, '2026-06', Money::of(200_000, 'TRY'));
            $issue->handle($building, '2026-07', Money::of(200_000, 'TRY'));
        });

        $rows = $this->inWorkspace($workspace, fn () => app(DebtorsReport::class)->handle($building));

        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            $this->assertSame(200_000, $row['owed']->minorUnits);
            $this->assertSame(2, $row['charges_count']);
            $this->assertSame('2026-06', $row['oldest_period']);
        }

        // Narrowing to one month halves what each unit owes.
        $july = $this->inWorkspace($workspace, fn () => app(DebtorsReport::class)->handle($building, '2026-07'));

        foreach ($july as $row) {
            $this->assertSame(100_000, $row['owed']->minorUnits);
        }
    }

    #[Test]
    public function issuing_refuses_a_building_with_no_units(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED);

        $this->expectException(BuildingsException::class);

        $this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(1000, 'TRY')));
    }

    #[Test]
    public function issuing_refuses_a_malformed_period(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED);
        $this->inWorkspace($workspace, fn () => $this->makeUnit($building, '1'));

        try {
            $this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
                ->handle($building, '2026-13', Money::of(1000, 'TRY')));

            $this->fail('A month of 13 must be refused.');
        } catch (BuildingsException $e) {
            $this->assertSame('invalid_period', $e->errorCode);
        }
    }

    #[Test]
    public function issuing_refuses_a_formula_that_gives_every_unit_zero_weight(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_PER_RESIDENT);

        $this->inWorkspace($workspace, function () use ($building): void {
            $this->makeUnit($building, 'E1', ['residents_count' => 0]);
            $this->makeUnit($building, 'E2', ['residents_count' => 0]);
        });

        try {
            $this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
                ->handle($building, '2026-07', Money::of(1000, 'TRY')));

            $this->fail('An all-zero split must be refused rather than divided by zero.');
        } catch (BuildingsException $e) {
            $this->assertSame('zero_weight_total', $e->errorCode);
        }
    }

    #[Test]
    public function buildings_units_and_charges_never_cross_workspaces(): void
    {
        [$workspaceA, $buildingA] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true, email: 'a@example.test');
        [$workspaceB, $buildingB] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true, email: 'b@example.test');

        $this->inWorkspace($workspaceA, function () use ($buildingA): void {
            $this->makeUnit($buildingA, 'A1');
            $this->makeUnit($buildingA, 'A2');

            app(IssuePeriodicCharges::class)->handle($buildingA, '2026-07', Money::of(200_000, 'TRY'));
        });

        $this->inWorkspace($workspaceB, function () use ($buildingB): void {
            $this->makeUnit($buildingB, 'B1');

            app(IssuePeriodicCharges::class)->handle($buildingB, '2026-07', Money::of(500_000, 'TRY'));
        });

        $this->inWorkspace($workspaceA, function () use ($buildingA, $buildingB): void {
            $this->assertSame(1, Building::query()->count());
            $this->assertSame($buildingA->id, Building::query()->sole()->id);
            $this->assertNull(Building::query()->find($buildingB->id));

            $this->assertSame(2, BuildingUnit::query()->count());
            $this->assertSame(2, BuildingCharge::query()->count());
            $this->assertSame(200_000, (int) BuildingCharge::query()->sum('amount'));
        });

        $this->inWorkspace($workspaceB, function () use ($buildingA): void {
            $this->assertSame(1, BuildingUnit::query()->count());
            $this->assertSame(1, BuildingCharge::query()->count());
            $this->assertSame(500_000, (int) BuildingCharge::query()->sum('amount'));
            $this->assertNull(Building::query()->find($buildingA->id));
        });

        // A debtors report can only ever see its own workspace's charges.
        $rowsA = $this->inWorkspace($workspaceA, fn () => app(DebtorsReport::class)->handle($buildingA));
        $this->assertSame(['A1', 'A2'], $rowsA->pluck('unit_no')->sort()->values()->all());

        $strayA = $this->inWorkspace($workspaceB, fn () => app(DebtorsReport::class)->handle($buildingA));
        $this->assertCount(0, $strayA, 'Workspace B must not see workspace A\'s debtors.');
    }

    #[Test]
    public function a_payment_in_the_wrong_currency_is_refused(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true);
        $this->inWorkspace($workspace, fn () => $this->makeUnit($building, 'C1'));

        $charge = $this->onlyCharge($this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(100_000, 'TRY'))));

        try {
            $this->inWorkspace($workspace, fn () => app(RecordChargePayment::class)
                ->handle($charge, Money::of(100, 'USD')));

            $this->fail('Paying a lira bill in dollars must be refused.');
        } catch (BuildingsException $e) {
            $this->assertSame('currency_mismatch', $e->errorCode);
        }
    }

    #[Test]
    public function the_api_issues_and_settles_charges_and_speaks_money_as_value_currency_minor_unit_decimal(): void
    {
        $manager = $this->makeUser('api-manager@example.test');
        $workspace = $this->makeWorkspace($manager, 'Pasdaran', 'TRY');
        $fund = $this->makeAccount($workspace, 'Building fund', 'TRY');

        Sanctum::actingAs($manager);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $building = $this->postJson('/api/v1/buildings', [
            'name' => 'Pasdaran Tower',
            'charge_formula' => Building::FORMULA_PER_AREA,
            'fund_account_id' => $fund->id,
        ], $headers)->assertCreated()->json('data');

        foreach ([['A', '60.00'], ['B', '40.00']] as [$unitNo, $area]) {
            $this->postJson("/api/v1/buildings/{$building['id']}/units", [
                'unit_no' => $unitNo,
                'area_m2' => $area,
                'residents_count' => 2,
            ], $headers)->assertCreated();
        }

        $issued = $this->postJson("/api/v1/buildings/{$building['id']}/charges/issue", [
            'period' => '2026-07',
            'total' => 1_000_001,
            'currency' => 'TRY',
            'due_date' => '2026-07-20',
        ], $headers)->assertCreated();

        $issued->assertJsonPath('meta.count', 2);
        $issued->assertJsonPath('meta.total.value', 1_000_001);
        $issued->assertJsonPath('meta.total.currency', 'TRY');
        $issued->assertJsonPath('meta.total.minor_unit', 2);
        $issued->assertJsonPath('meta.total.decimal', '10000.01');

        $charges = $issued->json('data');
        $this->assertSame(1_000_001, array_sum(array_column(array_column($charges, 'amount'), 'value')));

        // Re-issuing over HTTP answers 200 with the existing bills, not 201.
        $this->postJson("/api/v1/buildings/{$building['id']}/charges/issue", [
            'period' => '2026-07',
            'total' => 1_000_001,
            'currency' => 'TRY',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('meta.already_issued', true)
            ->assertJsonPath('meta.count', 2);

        $first = $charges[0];

        $this->postJson("/api/v1/building-charges/{$first['id']}/pay", [
            'amount' => 1000,
            'currency' => 'TRY',
        ], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', BuildingCharge::STATUS_PARTIAL)
            ->assertJsonPath('data.paid.value', 1000)
            ->assertJsonPath('data.remaining.value', $first['amount']['value'] - 1000);

        // Overpaying the rest by one minor unit is refused with a stable code.
        $this->postJson("/api/v1/building-charges/{$first['id']}/pay", [
            'amount' => $first['amount']['value'] - 999,
            'currency' => 'TRY',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'overpayment_refused');

        $this->getJson("/api/v1/buildings/{$building['id']}/reports/debtors", $headers)
            ->assertOk()
            ->assertJsonPath('meta.debtor_count', 2)
            ->assertJsonPath('meta.totals.TRY.value', 1_000_001 - 1000);

        $this->getJson("/api/v1/buildings/{$building['id']}/reports/fund", $headers)
            ->assertOk()
            ->assertJsonPath('data.balance.value', 1000)
            ->assertJsonPath('data.balance.currency', 'TRY')
            ->assertJsonPath('data.balance.minor_unit', 2)
            ->assertJsonPath('data.balance.decimal', '10.00')
            ->assertJsonPath('data.billed.value', 1_000_001)
            ->assertJsonPath('data.collected.value', 1000);
    }

    #[Test]
    public function a_viewer_may_read_the_books_but_not_issue_or_pay_charges(): void
    {
        [$workspace, $building] = $this->makeBuilding(Building::FORMULA_FIXED, withFund: true);
        $this->inWorkspace($workspace, fn () => $this->makeUnit($building, 'V1'));

        $charge = $this->onlyCharge($this->inWorkspace($workspace, fn () => app(IssuePeriodicCharges::class)
            ->handle($building, '2026-07', Money::of(100_000, 'TRY'))));

        $viewer = $this->makeUser('viewer@example.test');
        WorkspaceMember::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $viewer->id,
            'role' => WorkspaceMember::ROLE_VIEWER,
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($viewer);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->getJson("/api/v1/buildings/{$building->id}/charges", $headers)->assertOk();
        $this->getJson("/api/v1/buildings/{$building->id}/reports/debtors", $headers)->assertOk();

        $this->postJson("/api/v1/buildings/{$building->id}/charges/issue", [
            'period' => '2026-08',
            'total' => 100_000,
            'currency' => 'TRY',
        ], $headers)->assertForbidden();

        $this->postJson("/api/v1/building-charges/{$charge->id}/pay", [
            'amount' => 100,
            'currency' => 'TRY',
        ], $headers)->assertForbidden();

        $this->postJson("/api/v1/buildings/{$building->id}/units", [
            'unit_no' => 'V2',
        ], $headers)->assertForbidden();
    }

    #[Test]
    public function the_api_hides_another_workspaces_building_behind_your_own_header(): void
    {
        [, $buildingA] = $this->makeBuilding(Building::FORMULA_FIXED, email: 'owner-a@example.test');
        [$workspaceB] = $this->makeBuilding(Building::FORMULA_FIXED, email: 'owner-b@example.test');

        $intruder = $workspaceB->owner()->firstOrFail();

        Sanctum::actingAs($intruder);

        // A workspace the caller really is a member of, plus somebody else's
        // building id: the global scope, not the middleware, has to stop this.
        $this->getJson("/api/v1/buildings/{$buildingA->id}", ['X-Workspace-Id' => $workspaceB->id])
            ->assertNotFound();

        $this->getJson("/api/v1/buildings/{$buildingA->id}/reports/fund", ['X-Workspace-Id' => $workspaceB->id])
            ->assertNotFound();

        $this->getJson('/api/v1/buildings', ['X-Workspace-Id' => $workspaceB->id])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $buildingA->id]);
    }

    /**
     * @return array{0: Workspace, 1: Building, 2: Account|null}
     */
    private function makeBuilding(
        string $formula,
        bool $withFund = false,
        string $email = 'manager@example.test',
    ): array {
        $user = $this->makeUser($email);
        $workspace = $this->makeWorkspace($user, 'Pasdaran', 'TRY');

        $fund = $withFund ? $this->makeAccount($workspace, 'Building fund', 'TRY') : null;

        $building = $this->inWorkspace($workspace, fn () => Building::query()->create([
            'name' => 'Pasdaran Tower',
            'address' => 'Tehran',
            'charge_formula' => $formula,
            'fund_account_id' => $fund?->id,
        ]));

        return [$workspace, $building, $fund];
    }

    /**
     * The single charge an action just issued.
     *
     * Collection::first() is typed nullable for the empty case, which is never
     * the case here: the test has already created the unit the charge is for.
     *
     * @param  Collection<array-key, BuildingCharge>  $charges
     */
    private function onlyCharge(Collection $charges): BuildingCharge
    {
        $charge = $charges->first();

        $this->assertNotNull($charge, 'The period must have issued a charge.');

        return $charge;
    }

    /**
     * @param  Collection<string, BuildingCharge>  $charges  keyed by unit number
     */
    private function chargeFor(Collection $charges, string $key): BuildingCharge
    {
        $charge = $charges->get($key);

        $this->assertNotNull($charge, "No charge was issued against {$key}.");

        return $charge;
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeUnit(Building $building, string $unitNo, array $attributes = []): BuildingUnit
    {
        return BuildingUnit::query()->create(array_merge([
            'building_id' => $building->id,
            'unit_no' => $unitNo,
            'area_m2' => '80.00',
            'residents_count' => 2,
            'share_factor' => '1.0000',
            'owner_name' => "Owner {$unitNo}",
            'is_occupied' => true,
        ], $attributes));
    }
}
