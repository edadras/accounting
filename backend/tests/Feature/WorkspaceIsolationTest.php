<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cross-workspace leakage is the most severe bug this product can have: it
 * shows one person another person's money. These tests exercise the real HTTP
 * stack, not the models, because that is where a forgotten check would show up.
 */
final class WorkspaceIsolationTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_cannot_list_another_users_transactions(): void
    {
        [$intruder, $victimWorkspace] = $this->twoWorlds();

        Sanctum::actingAs($intruder);

        $response = $this->getJson('/api/v1/transactions', [
            'X-Workspace-Id' => $victimWorkspace->id,
        ]);

        $response->assertForbidden();
        $response->assertJsonPath('error.code', 'workspace_forbidden');
    }

    #[Test]
    public function a_user_cannot_read_another_workspaces_transaction_by_id(): void
    {
        [$intruder, $victimWorkspace, $victimTransaction] = $this->twoWorlds();

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/transactions/{$victimTransaction->id}", [
            'X-Workspace-Id' => $victimWorkspace->id,
        ])->assertForbidden();
    }

    #[Test]
    public function passing_your_own_workspace_header_does_not_expose_another_workspaces_record(): void
    {
        // The subtle attack: a valid header the caller *is* a member of, plus
        // an id belonging to somebody else. The global scope, not the
        // middleware, is what has to stop this one.
        [$intruder, , $victimTransaction, $intruderWorkspace] = $this->twoWorlds();

        Sanctum::actingAs($intruder);

        $this->getJson("/api/v1/transactions/{$victimTransaction->id}", [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();
    }

    #[Test]
    public function a_user_cannot_delete_another_workspaces_transaction(): void
    {
        [$intruder, , $victimTransaction, $intruderWorkspace] = $this->twoWorlds();

        Sanctum::actingAs($intruder);

        $this->deleteJson("/api/v1/transactions/{$victimTransaction->id}", headers: [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();

        $this->assertDatabaseHas('transactions', [
            'id' => $victimTransaction->id,
            'deleted_at' => null,
        ]);
    }

    #[Test]
    public function a_user_cannot_post_a_transaction_into_another_workspaces_account(): void
    {
        [$intruder, , , $intruderWorkspace, $victimAccount] = $this->twoWorlds();

        Sanctum::actingAs($intruder);

        $this->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $victimAccount->id,
            'amount' => 1000,
            'currency' => 'TRY',
        ], [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();
    }

    #[Test]
    public function a_missing_workspace_header_is_rejected_rather_than_defaulted(): void
    {
        [$intruder] = $this->twoWorlds();

        Sanctum::actingAs($intruder);

        $this->getJson('/api/v1/transactions')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'workspace_required');
    }

    #[Test]
    public function an_unauthenticated_request_never_reaches_the_ledger(): void
    {
        [, $victimWorkspace] = $this->twoWorlds();

        $this->getJson('/api/v1/transactions', [
            'X-Workspace-Id' => $victimWorkspace->id,
        ])->assertUnauthorized();
    }

    #[Test]
    public function a_query_with_no_active_workspace_returns_nothing_rather_than_everything(): void
    {
        // Fail closed: if the context is somehow never populated, the scope
        // must yield an empty set, not the whole table.
        $this->twoWorlds();

        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, Account::query()->count());
    }

    #[Test]
    public function a_viewer_may_read_but_not_write(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $workspace = $this->makeWorkspace($owner);
        $account = $this->makeAccount($workspace, openingBalance: 100000);

        $viewer = $this->makeUser('viewer@example.test');
        $workspace->members()->create([
            'user_id' => $viewer->id,
            'role' => 'viewer',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/transactions', ['X-Workspace-Id' => $workspace->id])
            ->assertOk();

        $this->postJson('/api/v1/transactions', [
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1000,
            'currency' => 'TRY',
        ], ['X-Workspace-Id' => $workspace->id])->assertForbidden();
    }

    /**
     * Builds two unrelated worlds and returns
     * [intruder, victimWorkspace, victimTransaction, intruderWorkspace, victimAccount].
     */
    private function twoWorlds(): array
    {
        $victim = $this->makeUser('victim@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim books');
        $victimAccount = $this->makeAccount($victimWorkspace, 'Victim wallet', 'TRY', 500000);

        $victimTransaction = $this->inWorkspace(
            $victimWorkspace,
            fn () => app(RecordTransaction::class)->handle([
                'type' => 'expense',
                'account_id' => $victimAccount->id,
                'amount' => 12345,
                'currency' => 'TRY',
                'description' => 'Private dinner',
            ]),
        );

        $intruder = $this->makeUser('intruder@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder books');

        app(WorkspaceContext::class)->forget();

        return [$intruder, $victimWorkspace, $victimTransaction, $intruderWorkspace, $victimAccount];
    }
}
