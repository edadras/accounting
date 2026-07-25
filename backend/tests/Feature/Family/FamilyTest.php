<?php

declare(strict_types=1);

namespace Tests\Feature\Family;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Family\Actions\PayAllowance;
use Modules\Family\Exceptions\FamilyException;
use Modules\Family\Models\AllowancePayment;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Queries\MemberSpending;
use Modules\Family\Support\Period;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\RegistersUnwiredProviders;
use Tests\Feature\LedgerTestCase;

/**
 * The promise of docs/02-modules.md §9: a household shares one set of books, a
 * child has a ceiling, and their allowance is money that actually moved rather
 * than a note that it should have.
 */
final class FamilyTest extends LedgerTestCase
{
    use RefreshDatabase;
    use RegistersUnwiredProviders;

    #[Test]
    public function spending_is_reported_per_member_against_their_cap(): void
    {
        $workspace = $this->workspace('cap@example.test');
        $wallet = $this->makeAccount($workspace, 'Family wallet', 'TRY', 10_000_00);

        $child = $this->member($workspace, 'Sara', FamilyMember::ROLE_CHILD, ['spending_cap' => 200_00]);
        $sibling = $this->member($workspace, 'Reza', FamilyMember::ROLE_CHILD, ['spending_cap' => 200_00]);

        $this->spend($workspace, $wallet, $child, 30_00, '2026-07-03 10:00:00');
        $this->spend($workspace, $wallet, $child, 45_00, '2026-07-20 10:00:00');
        $this->spend($workspace, $wallet, $sibling, 90_00, '2026-07-05 10:00:00');

        // Another month, and an untagged household expense: neither belongs to
        // Sara's July.
        $this->spend($workspace, $wallet, $child, 500_00, '2026-06-15 10:00:00');
        $this->spend($workspace, $wallet, null, 700_00, '2026-07-06 10:00:00');

        $report = $this->inWorkspace(
            $workspace,
            fn () => app(MemberSpending::class)->forMember($child->refresh(), Period::of('2026-07')),
        );

        $this->assertSame(75_00, $report['spent']->minorUnits);
        $this->assertSame(200_00, $report['cap']->minorUnits);
        $this->assertSame(125_00, $report['remaining']->minorUnits);
        $this->assertSame(37.5, $report['percentage']);
        $this->assertFalse($report['is_over_cap']);
    }

    #[Test]
    public function a_member_over_their_cap_is_flagged(): void
    {
        $workspace = $this->workspace('overcap@example.test');
        $wallet = $this->makeAccount($workspace, 'Family wallet', 'TRY', 10_000_00);
        $child = $this->member($workspace, 'Sara', FamilyMember::ROLE_CHILD, ['spending_cap' => 100_00]);

        $this->spend($workspace, $wallet, $child, 120_00, '2026-07-03 10:00:00');

        $report = $this->inWorkspace(
            $workspace,
            fn () => app(MemberSpending::class)->forMember($child->refresh(), Period::of('2026-07')),
        );

        $this->assertSame(120_00, $report['spent']->minorUnits);
        $this->assertSame(-20_00, $report['remaining']->minorUnits);
        $this->assertTrue($report['is_over_cap']);
    }

    #[Test]
    public function a_member_without_a_cap_is_reported_without_a_comparison(): void
    {
        $workspace = $this->workspace('nocap@example.test');
        $wallet = $this->makeAccount($workspace, 'Family wallet', 'TRY', 10_000_00);
        $parent = $this->member($workspace, 'Maryam', FamilyMember::ROLE_PARENT);

        $this->spend($workspace, $wallet, $parent, 60_00, '2026-07-03 10:00:00');

        $report = $this->inWorkspace(
            $workspace,
            fn () => app(MemberSpending::class)->forMember($parent->refresh(), Period::of('2026-07')),
        );

        $this->assertSame(60_00, $report['spent']->minorUnits);
        $this->assertNull($report['cap']);
        $this->assertNull($report['remaining']);
        $this->assertNull($report['percentage']);
        $this->assertFalse($report['is_over_cap']);
    }

    #[Test]
    public function paying_an_allowance_moves_money_between_both_accounts(): void
    {
        $workspace = $this->workspace('allowance@example.test');
        $parentAccount = $this->makeAccount($workspace, 'Parent bank', 'TRY', 1_000_00);
        $childAccount = $this->makeAccount($workspace, 'Sara wallet', 'TRY', 0);

        $parent = $this->member($workspace, 'Maryam', FamilyMember::ROLE_PARENT, [
            'account_id' => $parentAccount->id,
        ]);
        $child = $this->member($workspace, 'Sara', FamilyMember::ROLE_CHILD, [
            'monthly_allowance' => 150_00,
            'spending_cap' => 200_00,
            'account_id' => $childAccount->id,
        ]);

        $payment = $this->inWorkspace($workspace, fn () => app(PayAllowance::class)->handle(
            member: $child,
            payer: $parent,
            period: '2026-07',
            paidAt: new \DateTimeImmutable('2026-07-01 09:00:00'),
        ));

        $this->assertSame(150_00, $payment->amount);
        $this->assertSame('2026-07', $payment->period);
        $this->assertNotNull($payment->transaction_id);

        $transaction = $this->inWorkspace(
            $workspace,
            fn () => Transaction::query()->with('entries')->findOrFail($payment->transaction_id),
        );

        $this->assertSame(Transaction::TYPE_TRANSFER, $transaction->type);
        $this->assertSame($parentAccount->id, $transaction->account_id);
        $this->assertSame($childAccount->id, $transaction->counter_account_id);
        $this->assertSame([$child->tag()], $transaction->tags);
        $this->assertCount(2, $transaction->entries, 'An allowance must be a real double-entry posting.');

        $this->assertSame(850_00, $parentAccount->refresh()->current_balance);
        $this->assertSame(150_00, $childAccount->refresh()->current_balance);
    }

    #[Test]
    public function an_allowance_is_not_counted_as_the_childs_spending(): void
    {
        [$workspace, $parent, $child] = $this->household('notspend@example.test');

        $this->inWorkspace($workspace, fn () => app(PayAllowance::class)->handle(
            member: $child,
            payer: $parent,
            period: '2026-07',
            paidAt: new \DateTimeImmutable('2026-07-01 09:00:00'),
        ));

        $report = $this->inWorkspace(
            $workspace,
            fn () => app(MemberSpending::class)->forMember($child->refresh(), Period::of('2026-07')),
        );

        // Receiving money is not spending it; if it were, a child would exhaust
        // their cap on payday.
        $this->assertSame(0, $report['spent']->minorUnits);
    }

    #[Test]
    public function paying_the_same_period_twice_is_refused(): void
    {
        [$workspace, $parent, $child] = $this->household('twice@example.test');

        $this->inWorkspace($workspace, fn () => app(PayAllowance::class)->handle(
            member: $child,
            payer: $parent,
            period: '2026-07',
            paidAt: new \DateTimeImmutable('2026-07-01 09:00:00'),
        ));

        try {
            $this->inWorkspace($workspace, fn () => app(PayAllowance::class)->handle(
                member: $child,
                payer: $parent,
                period: '2026-07',
                paidAt: new \DateTimeImmutable('2026-07-02 09:00:00'),
            ));

            $this->fail('A second allowance for the same period must be refused.');
        } catch (FamilyException $e) {
            $this->assertSame('allowance_already_paid', $e->errorCode);
        }

        $this->assertSame(1, $this->inWorkspace($workspace, fn () => AllowancePayment::query()->count()));
        $this->assertSame(1, $this->inWorkspace(
            $workspace,
            fn () => Transaction::query()->ofType(Transaction::TYPE_TRANSFER)->count(),
        ));

        // The next month is a different obligation and still goes through.
        $august = $this->inWorkspace($workspace, fn () => app(PayAllowance::class)->handle(
            member: $child,
            payer: $parent,
            period: '2026-08',
            paidAt: new \DateTimeImmutable('2026-08-01 09:00:00'),
        ));

        $this->assertSame('2026-08', $august->period);
    }

    #[Test]
    public function an_allowance_needs_an_account_on_both_sides(): void
    {
        $workspace = $this->workspace('noaccount@example.test');
        $parentAccount = $this->makeAccount($workspace, 'Parent bank', 'TRY', 1_000_00);

        $parent = $this->member($workspace, 'Maryam', FamilyMember::ROLE_PARENT, ['account_id' => $parentAccount->id]);
        $child = $this->member($workspace, 'Sara', FamilyMember::ROLE_CHILD, ['monthly_allowance' => 50_00]);

        $this->expectException(FamilyException::class);

        $this->inWorkspace($workspace, fn () => app(PayAllowance::class)->handle(
            member: $child,
            payer: $parent,
            period: '2026-07',
        ));
    }

    #[Test]
    public function the_api_reports_spending_in_the_agreed_money_shape(): void
    {
        $workspace = $this->workspace('shape@example.test');
        $wallet = $this->makeAccount($workspace, 'Family wallet', 'TRY', 10_000_00);
        $child = $this->member($workspace, 'Sara', FamilyMember::ROLE_CHILD, ['spending_cap' => 200_00]);

        $this->spend($workspace, $wallet, $child, 50_00, '2026-07-03 10:00:00');

        $response = $this->asOwner($workspace)
            ->getJson('/api/v1/family/spending?period=2026-07', ['X-Workspace-Id' => $workspace->id]);

        $response->assertOk();
        $response->assertJsonPath('data.0.member_id', $child->id);
        $response->assertJsonPath('data.0.spent.value', 50_00);
        $response->assertJsonPath('data.0.spent.decimal', '50.00');
        $response->assertJsonPath('data.0.cap.value', 200_00);
        $response->assertJsonPath('data.0.remaining.value', 150_00);
        $response->assertJsonPath('meta.period', '2026-07');

        $this->assertSame(
            ['value', 'currency', 'minor_unit', 'decimal'],
            array_keys($response->json('data.0.spent')),
        );
    }

    #[Test]
    public function the_api_pays_an_allowance_and_refuses_the_second_attempt(): void
    {
        [$workspace, $parent, $child] = $this->household('api@example.test');

        $this->asOwner($workspace)->postJson(
            "/api/v1/family/members/{$child->id}/allowance",
            ['payer_member_id' => $parent->id, 'period' => '2026-07', 'paid_at' => '2026-07-01 09:00:00'],
            ['X-Workspace-Id' => $workspace->id],
        )
            ->assertCreated()
            ->assertJsonPath('data.period', '2026-07')
            ->assertJsonPath('data.amount.value', 150_00);

        $this->postJson(
            "/api/v1/family/members/{$child->id}/allowance",
            ['payer_member_id' => $parent->id, 'period' => '2026-07'],
            ['X-Workspace-Id' => $workspace->id],
        )
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'allowance_already_paid');
    }

    #[Test]
    public function a_viewer_may_read_the_household_but_not_change_it(): void
    {
        [$workspace, $parent, $child] = $this->household('viewer@example.test');

        $viewer = $this->makeUser('family-viewer@example.test');
        $workspace->members()->create(['user_id' => $viewer->id, 'role' => 'viewer', 'joined_at' => now()]);

        app(WorkspaceContext::class)->forget();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/family/members', ['X-Workspace-Id' => $workspace->id])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/family/members', [
            'display_name' => 'Ghost',
            'role' => 'child',
            'currency' => 'TRY',
        ], ['X-Workspace-Id' => $workspace->id])->assertForbidden();

        $this->postJson(
            "/api/v1/family/members/{$child->id}/allowance",
            ['payer_member_id' => $parent->id, 'period' => '2026-07'],
            ['X-Workspace-Id' => $workspace->id],
        )->assertForbidden();
    }

    #[Test]
    public function another_households_members_and_spending_never_leak(): void
    {
        $victimWorkspace = $this->workspace('victim-family@example.test');
        $victimWallet = $this->makeAccount($victimWorkspace, 'Victim wallet', 'TRY', 10_000_00);
        $victimChild = $this->member($victimWorkspace, 'Their child', FamilyMember::ROLE_CHILD, [
            'spending_cap' => 100_00,
        ]);
        $this->spend($victimWorkspace, $victimWallet, $victimChild, 90_00, '2026-07-03 10:00:00');

        $intruder = $this->makeUser('intruder-family@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder books');

        app(WorkspaceContext::class)->forget();
        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/family/members', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Their own header, somebody else's id: the global scope, not the
        // middleware, has to stop this one.
        $this->getJson("/api/v1/family/members/{$victimChild->id}", ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertNotFound();

        $this->getJson('/api/v1/family/spending?period=2026-07', ['X-Workspace-Id' => $intruderWorkspace->id])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Claiming the other household's workspace outright is refused at the door.
        $this->getJson('/api/v1/family/members', ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden();
    }

    #[Test]
    public function spending_tagged_by_a_member_of_another_workspace_is_not_counted(): void
    {
        $victimWorkspace = $this->workspace('tagleak-victim@example.test');
        $victimChild = $this->member($victimWorkspace, 'Their child', FamilyMember::ROLE_CHILD, [
            'spending_cap' => 100_00,
        ]);

        $ownWorkspace = $this->workspace('tagleak-owner@example.test');
        $ownWallet = $this->makeAccount($ownWorkspace, 'Own wallet', 'TRY', 10_000_00);

        // The other household's tag on this household's expense: the workspace
        // scope, not the tag, decides whose books it is.
        $this->inWorkspace($ownWorkspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $ownWallet->id,
            'amount' => 40_00,
            'currency' => 'TRY',
            'occurred_at' => '2026-07-03 10:00:00',
            'tags' => [$victimChild->tag()],
        ]));

        $report = $this->inWorkspace(
            $victimWorkspace,
            fn () => app(MemberSpending::class)->forMember($victimChild->refresh(), Period::of('2026-07')),
        );

        $this->assertSame(0, $report['spent']->minorUnits);
    }

    // --- helpers -----------------------------------------------------------

    private function workspace(string $email, string $currency = 'TRY'): Workspace
    {
        return $this->makeWorkspace($this->makeUser($email), currency: $currency);
    }

    /** @param  array<string, mixed>  $attributes */
    private function member(
        Workspace $workspace,
        string $displayName,
        string $role,
        array $attributes = [],
    ): FamilyMember {
        return $this->inWorkspace($workspace, fn () => FamilyMember::query()->create(array_merge([
            'display_name' => $displayName,
            'role' => $role,
            'currency' => 'TRY',
        ], $attributes)));
    }

    /**
     * A household with a parent who can pay and a child who is owed ₺150.
     *
     * @return array{0: Workspace, 1: FamilyMember, 2: FamilyMember}
     */
    private function household(string $email): array
    {
        $workspace = $this->workspace($email);
        $parentAccount = $this->makeAccount($workspace, 'Parent bank', 'TRY', 1_000_00);
        $childAccount = $this->makeAccount($workspace, 'Child wallet', 'TRY', 0);

        $parent = $this->member($workspace, 'Maryam', FamilyMember::ROLE_PARENT, [
            'account_id' => $parentAccount->id,
        ]);

        $child = $this->member($workspace, 'Sara', FamilyMember::ROLE_CHILD, [
            'monthly_allowance' => 150_00,
            'spending_cap' => 200_00,
            'account_id' => $childAccount->id,
        ]);

        return [$workspace, $parent, $child];
    }

    private function spend(
        Workspace $workspace,
        Account $account,
        ?FamilyMember $member,
        int $amount,
        string $occurredAt,
    ): Transaction {
        return $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => $amount,
            'currency' => 'TRY',
            'occurred_at' => $occurredAt,
            'tags' => $member === null ? null : [$member->tag()],
        ]));
    }

    private function asOwner(Workspace $workspace): self
    {
        app(WorkspaceContext::class)->forget();

        Sanctum::actingAs($workspace->owner()->firstOrFail());

        return $this;
    }
}
