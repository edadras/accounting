<?php

declare(strict_types=1);

namespace Tests\Feature\Budget;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Budget\Actions\CalculateBudgetUsage;
use Modules\Budget\Actions\RolloverBudget;
use Modules\Budget\Models\Budget;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * What a budget is allowed to count is the whole product here: a budget that
 * counts a transfer, or misses a child category, tells the user a number that
 * is simply wrong.
 */
final class BudgetTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_category_budget_counts_spending_in_child_categories(): void
    {
        $workspace = $this->workspace('subtree@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 10_000_00);

        $food = $this->makeCategory($workspace, 'Food');
        $restaurant = $this->makeCategory($workspace, 'Restaurant', $food);
        $transport = $this->makeCategory($workspace, 'Transport');

        $this->spend($workspace, $account, $food, 10_00, '2026-07-03 10:00:00');
        $this->spend($workspace, $account, $restaurant, 25_00, '2026-07-04 10:00:00');
        $this->spend($workspace, $account, $transport, 90_00, '2026-07-05 10:00:00');

        $budget = $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
            'amount' => 100_00,
        ]);

        $usage = $this->inWorkspace(
            $workspace,
            fn () => app(CalculateBudgetUsage::class)->handle($budget, $this->at('2026-07-20 12:00:00')),
        );

        $this->assertSame(35_00, $usage->spent_amount, 'The child category must count toward its parent.');
        $this->assertSame('2026-07', $usage->period_key);
        $this->assertDatabaseHas('budget_usages', [
            'budget_id' => $budget->id,
            'period_key' => '2026-07',
            'spent_amount' => 35_00,
        ]);
    }

    #[Test]
    public function a_grandchild_category_still_counts_toward_the_root_budget(): void
    {
        $workspace = $this->workspace('deep@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 10_000_00);

        $home = $this->makeCategory($workspace, 'Home');
        $food = $this->makeCategory($workspace, 'Food', $home);
        $bread = $this->makeCategory($workspace, 'Bread', $food);

        $this->spend($workspace, $account, $bread, 7_00, '2026-07-09 10:00:00');

        $budget = $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $home->id,
        ]);

        $usage = $this->inWorkspace(
            $workspace,
            fn () => app(CalculateBudgetUsage::class)->handle($budget, $this->at('2026-07-20 12:00:00')),
        );

        $this->assertSame(7_00, $usage->spent_amount);
    }

    #[Test]
    public function spending_outside_the_period_is_not_counted(): void
    {
        $workspace = $this->workspace('window@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 10_000_00);
        $food = $this->makeCategory($workspace, 'Food');

        $this->spend($workspace, $account, $food, 40_00, '2026-06-30 23:59:00');
        $this->spend($workspace, $account, $food, 11_00, '2026-07-01 00:00:00');
        $this->spend($workspace, $account, $food, 13_00, '2026-07-31 23:59:00');
        $this->spend($workspace, $account, $food, 50_00, '2026-08-01 00:00:01');

        $budget = $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
        ]);

        $usage = $this->inWorkspace(
            $workspace,
            fn () => app(CalculateBudgetUsage::class)->handle($budget, $this->at('2026-07-15 12:00:00')),
        );

        $this->assertSame(24_00, $usage->spent_amount, 'Only July may count toward the July period.');
    }

    #[Test]
    public function a_transfer_between_your_own_accounts_is_never_budget_spend(): void
    {
        // Moving money from the bank to the wallet is not spending it. If a
        // transfer counted, topping up a wallet would exhaust a budget.
        $workspace = $this->workspace('transfer@example.test');
        $bank = $this->makeAccount($workspace, 'Bank', 'TRY', 10_000_00);
        $wallet = $this->makeAccount($workspace, 'Wallet', 'TRY', 0);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'transfer',
            'account_id' => $bank->id,
            'counter_account_id' => $wallet->id,
            'amount' => 500_00,
            'currency' => 'TRY',
            'occurred_at' => '2026-07-10 10:00:00',
        ]));

        $budget = $this->makeBudget($workspace, ['scope' => Budget::SCOPE_OVERALL, 'scope_id' => null]);

        $usage = $this->inWorkspace(
            $workspace,
            fn () => app(CalculateBudgetUsage::class)->handle($budget, $this->at('2026-07-20 12:00:00')),
        );

        $this->assertSame(0, $usage->spent_amount);
    }

    #[Test]
    public function income_is_never_budget_spend(): void
    {
        $workspace = $this->workspace('income@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 0);

        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'income',
            'account_id' => $account->id,
            'amount' => 5_000_00,
            'currency' => 'TRY',
            'occurred_at' => '2026-07-02 10:00:00',
        ]));

        $this->spend($workspace, $account, null, 20_00, '2026-07-03 10:00:00');

        $budget = $this->makeBudget($workspace, ['scope' => Budget::SCOPE_OVERALL, 'scope_id' => null]);

        $usage = $this->inWorkspace(
            $workspace,
            fn () => app(CalculateBudgetUsage::class)->handle($budget, $this->at('2026-07-20 12:00:00')),
        );

        $this->assertSame(20_00, $usage->spent_amount, 'A payday must not cancel out spending.');
    }

    #[Test]
    public function rollover_carries_the_unspent_remainder_into_the_next_period(): void
    {
        $workspace = $this->workspace('rollover@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 10_000_00);
        $food = $this->makeCategory($workspace, 'Food');

        // June: ₺30 of a ₺100 budget spent, so ₺70 is left over.
        $this->spend($workspace, $account, $food, 30_00, '2026-06-10 10:00:00');

        $budget = $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
            'amount' => 100_00,
            'rollover' => true,
            'starts_at' => '2026-06-01 00:00:00',
        ]);

        $effective = $this->inWorkspace(
            $workspace,
            fn () => app(RolloverBudget::class)->handle($budget, $this->at('2026-07-15 12:00:00')),
        );

        $this->assertSame(170_00, $effective->minorUnits);
        $this->assertSame(100_00, $budget->fresh()->amount, 'Rollover must not rewrite the stored amount.');
    }

    #[Test]
    public function without_rollover_the_remainder_is_dropped(): void
    {
        $workspace = $this->workspace('norollover@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 10_000_00);
        $food = $this->makeCategory($workspace, 'Food');

        $this->spend($workspace, $account, $food, 30_00, '2026-06-10 10:00:00');

        $budget = $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
            'amount' => 100_00,
            'rollover' => false,
            'starts_at' => '2026-06-01 00:00:00',
        ]);

        $effective = $this->inWorkspace(
            $workspace,
            fn () => app(RolloverBudget::class)->handle($budget, $this->at('2026-07-15 12:00:00')),
        );

        $this->assertSame(100_00, $effective->minorUnits);
    }

    #[Test]
    public function rollover_does_not_reach_back_past_the_budgets_own_start(): void
    {
        $workspace = $this->workspace('freshstart@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 10_000_00);
        $food = $this->makeCategory($workspace, 'Food');

        $this->spend($workspace, $account, $food, 30_00, '2026-06-10 10:00:00');

        $budget = $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
            'amount' => 100_00,
            'rollover' => true,
            'starts_at' => '2026-07-01 00:00:00',
        ]);

        $effective = $this->inWorkspace(
            $workspace,
            fn () => app(RolloverBudget::class)->handle($budget, $this->at('2026-07-15 12:00:00')),
        );

        $this->assertSame(100_00, $effective->minorUnits, 'A period before the budget existed cannot lend it money.');
    }

    #[Test]
    public function an_overspent_period_does_not_hand_the_next_one_a_debt(): void
    {
        $workspace = $this->workspace('overspent@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 10_000_00);
        $food = $this->makeCategory($workspace, 'Food');

        $this->spend($workspace, $account, $food, 150_00, '2026-06-10 10:00:00');

        $budget = $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
            'amount' => 100_00,
            'rollover' => true,
            'starts_at' => '2026-06-01 00:00:00',
        ]);

        $effective = $this->inWorkspace(
            $workspace,
            fn () => app(RolloverBudget::class)->handle($budget, $this->at('2026-07-15 12:00:00')),
        );

        $this->assertSame(100_00, $effective->minorUnits);
    }

    #[Test]
    public function no_alert_fires_below_eighty_percent(): void
    {
        $status = $this->statusFor(79_99);

        $this->assertSame(79.99, (float) $status['percentage']);
        $this->assertSame([], $status['thresholds_crossed']);
    }

    #[Test]
    public function the_eighty_percent_alert_fires_exactly_at_eighty(): void
    {
        $status = $this->statusFor(80_00);

        $this->assertSame(80.0, (float) $status['percentage']);
        $this->assertSame([80], $status['thresholds_crossed']);
    }

    #[Test]
    public function the_hundred_percent_alert_fires_only_once_the_budget_is_exhausted(): void
    {
        $ninetyNine = $this->statusFor(99_99);
        $this->assertSame([80], $ninetyNine['thresholds_crossed']);

        $exhausted = $this->statusFor(100_00);
        $this->assertSame(100.0, (float) $exhausted['percentage']);
        $this->assertSame([80, 100], $exhausted['thresholds_crossed']);
        $this->assertSame(0, $exhausted['remaining']['value']);
        $this->assertFalse($exhausted['is_over_budget']);

        $over = $this->statusFor(120_00);
        $this->assertSame([80, 100], $over['thresholds_crossed']);
        $this->assertSame(-20_00, $over['remaining']['value']);
        $this->assertTrue($over['is_over_budget']);
    }

    #[Test]
    public function the_status_endpoint_reports_money_in_the_agreed_shape(): void
    {
        $status = $this->statusFor(25_00);

        foreach (['amount', 'effective_amount', 'spent', 'remaining'] as $key) {
            $this->assertSame(
                ['value', 'currency', 'minor_unit', 'decimal'],
                array_keys($status[$key]),
                "Money field [{$key}] is not in the agreed shape.",
            );
        }

        $this->assertSame(25_00, $status['spent']['value']);
        $this->assertSame('TRY', $status['spent']['currency']);
        $this->assertSame(2, $status['spent']['minor_unit']);
        $this->assertSame('25.00', $status['spent']['decimal']);
        $this->assertSame(100_00, $status['effective_amount']['value']);
        $this->assertSame(75_00, $status['remaining']['value']);
        $this->assertSame('2026-07', $status['period_key']);
    }

    #[Test]
    public function the_status_endpoint_reflects_rollover_in_the_effective_amount(): void
    {
        $workspace = $this->workspace('statusrollover@example.test');
        $account = $this->makeAccount($workspace, openingBalance: 100_000_00);
        $food = $this->makeCategory($workspace, 'Food');

        $this->spend($workspace, $account, $food, 40_00, '2026-06-10 10:00:00');
        $this->spend($workspace, $account, $food, 30_00, '2026-07-10 10:00:00');

        $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
            'amount' => 100_00,
            'rollover' => true,
            'starts_at' => '2026-06-01 00:00:00',
        ]);

        $status = $this->fetchStatus($workspace, '2026-07-15 12:00:00')[0];

        $this->assertSame(100_00, $status['amount']['value']);
        $this->assertSame(160_00, $status['effective_amount']['value']);
        $this->assertSame(30_00, $status['spent']['value']);
        $this->assertSame(130_00, $status['remaining']['value']);
        $this->assertSame(18.75, (float) $status['percentage']);
    }

    #[Test]
    public function a_budget_in_another_workspace_is_invisible(): void
    {
        $victim = $this->makeUser('victim-budget@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim books');
        $victimBudget = $this->makeBudget($victimWorkspace, ['name' => 'Private groceries']);

        $intruder = $this->makeUser('intruder-budget@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder books');

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($intruder);

        // Their own header, somebody else's id: the global scope, not the
        // middleware, has to stop this one.
        $this->getJson("/api/v1/budgets/{$victimBudget->id}", [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();

        $this->deleteJson("/api/v1/budgets/{$victimBudget->id}", headers: [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();

        $this->getJson('/api/v1/budgets', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/budgets/status', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Claiming the victim's workspace outright is refused at the door.
        $this->getJson('/api/v1/budgets', ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden();

        $this->assertDatabaseHas('budgets', ['id' => $victimBudget->id, 'deleted_at' => null]);
    }

    #[Test]
    public function another_workspaces_spending_never_leaks_into_a_budget(): void
    {
        $victim = $this->makeUser('leak-victim@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim books');
        $victimAccount = $this->makeAccount($victimWorkspace, 'Victim wallet', 'TRY', 10_000_00);
        $this->spend($victimWorkspace, $victimAccount, null, 900_00, '2026-07-10 10:00:00');

        $owner = $this->makeUser('leak-owner@example.test');
        $ownWorkspace = $this->makeWorkspace($owner, 'Own books');
        $ownAccount = $this->makeAccount($ownWorkspace, 'Own wallet', 'TRY', 10_000_00);
        $this->spend($ownWorkspace, $ownAccount, null, 10_00, '2026-07-11 10:00:00');

        $budget = $this->makeBudget($ownWorkspace, ['scope' => Budget::SCOPE_OVERALL, 'scope_id' => null]);

        $usage = $this->inWorkspace(
            $ownWorkspace,
            fn () => app(CalculateBudgetUsage::class)->handle($budget, $this->at('2026-07-20 12:00:00')),
        );

        $this->assertSame(10_00, $usage->spent_amount);
    }

    #[Test]
    public function a_viewer_may_read_budgets_but_not_create_or_delete_them(): void
    {
        $owner = $this->makeUser('budget-owner@example.test');
        $workspace = $this->makeWorkspace($owner);
        $budget = $this->makeBudget($workspace);

        $viewer = $this->makeUser('budget-viewer@example.test');
        $workspace->members()->create([
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now(),
        ]);

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/budgets', ['X-Workspace-Id' => $workspace->id])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->postJson('/api/v1/budgets', [
            'name' => 'Sneaky',
            'scope' => 'overall',
            'period' => 'monthly',
            'starts_at' => '2026-07-01 00:00:00',
            'amount' => 1000,
            'currency' => 'TRY',
        ], ['X-Workspace-Id' => $workspace->id])->assertForbidden();

        $this->deleteJson("/api/v1/budgets/{$budget->id}", headers: [
            'X-Workspace-Id' => $workspace->id,
        ])->assertForbidden();
    }

    #[Test]
    public function a_created_budget_defaults_to_the_eighty_and_hundred_percent_thresholds(): void
    {
        $owner = $this->makeUser('thresholds@example.test');
        $workspace = $this->makeWorkspace($owner);

        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/budgets', [
            'name' => 'Groceries',
            'scope' => 'overall',
            'period' => 'monthly',
            'starts_at' => '2026-07-01 00:00:00',
            'amount' => 100_00,
            'currency' => 'TRY',
        ], ['X-Workspace-Id' => $workspace->id])
            ->assertCreated()
            ->assertJsonPath('data.alert_thresholds', [80, 100])
            ->assertJsonPath('data.amount.decimal', '100.00');
    }

    // --- helpers -----------------------------------------------------------

    private function workspace(string $email, string $currency = 'TRY'): Workspace
    {
        return $this->makeWorkspace($this->makeUser($email), currency: $currency);
    }

    /** @param  array<string, mixed>  $attributes */
    private function makeBudget(Workspace $workspace, array $attributes = []): Budget
    {
        return $this->inWorkspace($workspace, fn () => Budget::query()->create(array_merge([
            'name' => 'Monthly budget',
            'scope' => Budget::SCOPE_OVERALL,
            'scope_id' => null,
            'period' => Budget::PERIOD_MONTHLY,
            'starts_at' => '2026-07-01 00:00:00',
            'ends_at' => null,
            'amount' => 100_00,
            'currency' => 'TRY',
            'rollover' => false,
        ], $attributes)));
    }

    private function spend(
        Workspace $workspace,
        Account $account,
        ?Category $category,
        int $amount,
        string $occurredAt,
    ): void {
        $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'amount' => $amount,
            'currency' => 'TRY',
            'occurred_at' => $occurredAt,
        ]));
    }

    private function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }

    /**
     * Status for a ₺100 monthly budget that has had $spent spent against it.
     *
     * @return array<string, mixed>
     */
    private function statusFor(int $spent): array
    {
        static $seq = 0;
        $seq++;

        $workspace = $this->workspace("threshold{$seq}@example.test");
        $account = $this->makeAccount($workspace, openingBalance: 1_000_000_00);
        $food = $this->makeCategory($workspace, 'Food');

        $this->spend($workspace, $account, $food, $spent, '2026-07-10 10:00:00');

        $this->makeBudget($workspace, [
            'scope' => Budget::SCOPE_CATEGORY,
            'scope_id' => $food->id,
            'amount' => 100_00,
        ]);

        return $this->fetchStatus($workspace, '2026-07-15 12:00:00')[0];
    }

    /** @return list<array<string, mixed>> */
    private function fetchStatus(Workspace $workspace, string $at): array
    {
        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($workspace->owner);

        $response = $this->getJson(
            '/api/v1/budgets/status?at='.urlencode($at),
            ['X-Workspace-Id' => $workspace->id],
        );

        $response->assertOk();

        return $response->json('data');
    }
}
