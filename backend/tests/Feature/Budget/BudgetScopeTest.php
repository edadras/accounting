<?php

declare(strict_types=1);

namespace Tests\Feature\Budget;

use App\Core\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budget\Actions\CalculateBudgetUsage;
use Modules\Budget\Models\Budget;
use Modules\Buildings\Actions\RecordBuildingExpense;
use Modules\Buildings\Models\Building;
use Modules\Business\Models\Project;
use Modules\Core\Models\Workspace;
use Modules\Family\Models\FamilyMember;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Travel\Actions\SplitExpense;
use Modules\Travel\Models\Trip;
use Modules\Travel\Models\TripMember;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersUnwiredProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The scopes `Budget::SCOPES` always promised but `CalculateBudgetUsage` used to
 * answer zero for, now that the modules owning those tables exist.
 *
 * Each case asserts both halves of the same claim: the scope counts what belongs
 * to it, and it counts nothing else.
 */
final class BudgetScopeTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersUnwiredProviders;

    #[Test]
    public function a_project_budget_counts_only_transactions_tagged_with_that_project(): void
    {
        $workspace = $this->workspace('project-scope@example.test');
        $account = $this->makeAccount($workspace, 'Company bank', 'TRY', 1_000_000_00);

        $project = $this->inWorkspace($workspace, fn () => Project::query()->create([
            'name' => 'Pasdaran build',
            'status' => Project::STATUS_ACTIVE,
            'budget_amount' => 500_00,
            'currency' => 'TRY',
        ]));

        $other = $this->inWorkspace($workspace, fn () => Project::query()->create([
            'name' => 'Other build',
            'status' => Project::STATUS_ACTIVE,
            'budget_amount' => 500_00,
            'currency' => 'TRY',
        ]));

        $this->spend($workspace, $account, 60_00, '2026-07-03 10:00:00', [$project->tag()]);
        $this->spend($workspace, $account, 40_00, '2026-07-19 10:00:00', [$project->tag(), 'urgent']);
        $this->spend($workspace, $account, 900_00, '2026-07-04 10:00:00', [$other->tag()]);
        $this->spend($workspace, $account, 700_00, '2026-07-05 10:00:00', null);
        $this->spend($workspace, $account, 300_00, '2026-08-02 10:00:00', [$project->tag()]);

        $this->assertSpent(100_00, $workspace, 'project', $project->id);
    }

    #[Test]
    public function a_building_budget_counts_only_that_buildings_expenses(): void
    {
        $workspace = $this->workspace('building-scope@example.test');

        $fund = $this->makeAccount($workspace, 'Pasdaran fund', 'TRY', 1_000_000_00);
        $otherFund = $this->makeAccount($workspace, 'Vanak fund', 'TRY', 1_000_000_00);
        $petty = $this->makeAccount($workspace, 'Petty cash', 'TRY', 1_000_000_00);

        $building = $this->building($workspace, 'Pasdaran', $fund);
        $otherBuilding = $this->building($workspace, 'Vanak', $otherFund);

        $this->buildingExpense($workspace, $building, 120_00, '2026-07-04 10:00:00');
        $this->buildingExpense($workspace, $building, 80_00, '2026-07-18 10:00:00');
        $this->buildingExpense($workspace, $otherBuilding, 500_00, '2026-07-06 10:00:00');
        $this->buildingExpense($workspace, $building, 400_00, '2026-08-01 10:00:00');

        // An ordinary expense that has nothing to do with any building.
        $this->spend($workspace, $petty, 900_00, '2026-07-07 10:00:00', null);

        $this->assertSpent(200_00, $workspace, 'building', $building->id);
    }

    #[Test]
    public function a_member_budget_counts_only_that_members_spending(): void
    {
        $workspace = $this->workspace('member-scope@example.test');
        $account = $this->makeAccount($workspace, 'Family wallet', 'TRY', 1_000_000_00);

        $sara = $this->familyMember($workspace, 'Sara');
        $reza = $this->familyMember($workspace, 'Reza');

        $this->spend($workspace, $account, 30_00, '2026-07-03 10:00:00', [$sara->tag()]);
        $this->spend($workspace, $account, 45_00, '2026-07-21 10:00:00', [$sara->tag()]);
        $this->spend($workspace, $account, 500_00, '2026-07-04 10:00:00', [$reza->tag()]);
        $this->spend($workspace, $account, 700_00, '2026-07-05 10:00:00', null);
        $this->spend($workspace, $account, 300_00, '2026-06-30 10:00:00', [$sara->tag()]);

        $this->assertSpent(75_00, $workspace, 'member', $sara->id);
    }

    #[Test]
    public function a_trip_budget_counts_only_that_trips_split_expenses(): void
    {
        $workspace = $this->workspace('trip-scope@example.test');

        $istanbul = $this->trip($workspace, 'Istanbul');
        $shiraz = $this->trip($workspace, 'Shiraz');

        $this->tripExpense($workspace, $istanbul, 120_00, '2026-07-04 10:00:00');
        $this->tripExpense($workspace, $istanbul, 80_00, '2026-07-22 10:00:00');
        $this->tripExpense($workspace, $shiraz, 900_00, '2026-07-06 10:00:00');
        $this->tripExpense($workspace, $istanbul, 400_00, '2026-08-03 10:00:00');

        // A ledger expense in the same month, belonging to no trip at all.
        $wallet = $this->makeAccount($workspace, 'Wallet', 'TRY', 1_000_000_00);
        $this->spend($workspace, $wallet, 700_00, '2026-07-07 10:00:00', null);

        $this->assertSpent(200_00, $workspace, 'trip', $istanbul->id);
    }

    #[Test]
    public function a_trip_kept_in_another_currency_reports_zero_rather_than_a_converted_guess(): void
    {
        $workspace = $this->workspace('trip-currency@example.test');

        $trip = $this->trip($workspace, 'Dubai', 'USD');
        $this->tripExpense($workspace, $trip, 100_00, '2026-07-04 10:00:00', 'USD');

        // The split expense's base amount is frozen in the trip's currency and
        // carries no rate to the workspace's, so there is nothing honest to add.
        $this->assertSpent(0, $workspace, 'trip', $trip->id);
    }

    #[Test]
    public function a_scope_pointing_at_nothing_still_reports_zero(): void
    {
        $workspace = $this->workspace('dangling-scope@example.test');
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 1_000_000_00);

        $this->spend($workspace, $account, 900_00, '2026-07-05 10:00:00', null);

        foreach (['project', 'trip', 'building', 'member'] as $scope) {
            $this->assertSpent(0, $workspace, $scope, null);
            $this->assertSpent(0, $workspace, $scope, '01JZZZZZZZZZZZZZZZZZZZZZZZ');
        }
    }

    #[Test]
    public function another_workspaces_scoped_spending_never_leaks_in(): void
    {
        $theirs = $this->workspace('scope-victim@example.test');
        $theirAccount = $this->makeAccount($theirs, 'Their wallet', 'TRY', 1_000_000_00);
        $theirMember = $this->familyMember($theirs, 'Their child');
        $this->spend($theirs, $theirAccount, 900_00, '2026-07-05 10:00:00', [$theirMember->tag()]);

        $mine = $this->workspace('scope-owner@example.test');
        $myAccount = $this->makeAccount($mine, 'My wallet', 'TRY', 1_000_000_00);
        $this->spend($mine, $myAccount, 10_00, '2026-07-05 10:00:00', [$theirMember->tag()]);

        // The tag is the other household's, but the books are mine: only my own
        // row may be counted, and only for a budget in my own workspace.
        $this->assertSpent(10_00, $mine, 'member', $theirMember->id);
    }

    // --- helpers -----------------------------------------------------------

    private function assertSpent(int $expected, Workspace $workspace, string $scope, ?string $scopeId): void
    {
        $budget = $this->inWorkspace($workspace, fn () => Budget::query()->create([
            'name' => "{$scope} budget",
            'scope' => $scope,
            'scope_id' => $scopeId,
            'period' => Budget::PERIOD_MONTHLY,
            'starts_at' => '2026-07-01 00:00:00',
            'amount' => 1_000_00,
            'currency' => 'TRY',
        ]));

        $usage = $this->inWorkspace(
            $workspace,
            fn () => app(CalculateBudgetUsage::class)->handle($budget, new \DateTimeImmutable('2026-07-20 12:00:00')),
        );

        $this->assertSame($expected, $usage->spent_amount, "Scope [{$scope}] counted the wrong spend.");
        $this->assertSame('2026-07', $usage->period_key);
    }

    private function workspace(string $email, string $currency = 'TRY'): Workspace
    {
        return $this->makeWorkspace($this->makeUser($email), currency: $currency);
    }

    /** @param  list<string>|null  $tags */
    private function spend(
        Workspace $workspace,
        Account $account,
        int $amount,
        string $occurredAt,
        ?array $tags,
    ): void {
        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => 'TRY',
            'occurred_at' => $occurredAt,
            'tags' => $tags,
        ]));
    }

    private function building(Workspace $workspace, string $name, Account $fund): Building
    {
        return $this->inWorkspace($workspace, fn () => Building::query()->create([
            'name' => $name,
            'fund_account_id' => $fund->id,
            'charge_formula' => Building::FORMULA_FIXED,
        ]));
    }

    private function buildingExpense(Workspace $workspace, Building $building, int $amount, string $occurredAt): void
    {
        $this->inWorkspace($workspace, fn () => app(RecordBuildingExpense::class)->handle(
            building: $building,
            amount: Money::of($amount, 'TRY'),
            occurredAt: new \DateTimeImmutable($occurredAt),
            description: 'Lift service',
        ));
    }

    private function familyMember(Workspace $workspace, string $name): FamilyMember
    {
        return $this->inWorkspace($workspace, fn () => FamilyMember::query()->create([
            'display_name' => $name,
            'role' => FamilyMember::ROLE_CHILD,
            'currency' => 'TRY',
            'spending_cap' => 200_00,
        ]));
    }

    private function trip(Workspace $workspace, string $name, string $currency = 'TRY'): Trip
    {
        return $this->inWorkspace($workspace, function () use ($name, $currency): Trip {
            $trip = Trip::query()->create([
                'name' => $name,
                'base_currency' => $currency,
            ]);

            foreach (['Ali', 'Sara'] as $member) {
                TripMember::query()->create([
                    'trip_id' => $trip->id,
                    'display_name' => $member,
                ]);
            }

            return $trip;
        });
    }

    private function tripExpense(
        Workspace $workspace,
        Trip $trip,
        int $amount,
        string $occurredAt,
        string $currency = 'TRY',
    ): void {
        $this->inWorkspace($workspace, function () use ($trip, $amount, $occurredAt, $currency): void {
            $payer = TripMember::query()->where('trip_id', $trip->id)->orderBy('id')->firstOrFail();

            app(SplitExpense::class)->handle($trip, [
                'payer_member_id' => $payer->id,
                'amount' => $amount,
                'currency' => $currency,
                'occurred_at' => $occurredAt,
                'description' => 'Dinner',
            ]);
        });
    }
}
