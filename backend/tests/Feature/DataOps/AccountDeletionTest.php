<?php

declare(strict_types=1);

namespace Tests\Feature\DataOps;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;
use PHPUnit\Framework\Attributes\Test;

final class AccountDeletionTest extends DataOpsTestCase
{
    private const PASSWORD = 'password123';

    use RefreshDatabase;

    #[Test]
    public function scheduling_a_deletion_blocks_signing_in_and_restoring_it_lets_the_user_back(): void
    {
        $user = $this->makeUser('ali@example.test');
        $this->makeWorkspace($user);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/me', ['password' => self::PASSWORD])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'deletion_scheduled')
            ->assertJsonPath('data.grace_days', (int) config('dataops.deletion.grace_days'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.deletion_scheduled',
            'user_id' => $user->id,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ali@example.test',
            'password' => self::PASSWORD,
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_pending_deletion');

        // The account still exists — nothing has been erased yet.
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        Sanctum::actingAs($user->fresh());

        $this->postJson('/api/v1/me/restore')
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.deletion_cancelled',
            'user_id' => $user->id,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'ali@example.test',
            'password' => self::PASSWORD,
        ])->assertOk();
    }

    #[Test]
    public function deletion_requires_the_current_password(): void
    {
        $user = $this->makeUser('ali@example.test');

        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/me', ['password' => 'not-my-password'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'incorrect_password');

        $this->assertNull($user->fresh()->deletion_requested_at);

        $this->deleteJson('/api/v1/me', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNull($user->fresh()->deletion_requested_at);
    }

    #[Test]
    public function restoring_an_account_that_was_never_scheduled_is_refused(): void
    {
        $user = $this->makeUser('ali@example.test');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/me/restore')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'deletion_not_scheduled');
    }

    #[Test]
    public function purge_removes_only_the_accounts_whose_window_has_passed(): void
    {
        $due = $this->makeUser('due@example.test');
        $waiting = $this->makeUser('waiting@example.test');

        $this->schedule($due, daysAgo: 31, purgeAfterDaysFromNow: -1);
        $this->schedule($waiting, daysAgo: 1, purgeAfterDaysFromNow: 29);

        $this->artisan('accounts:purge')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $due->id]);
        $this->assertDatabaseHas('users', ['id' => $waiting->id]);
    }

    #[Test]
    public function purging_a_sole_owner_erases_the_workspace_but_a_shared_one_survives(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $admin = $this->makeUser('admin@example.test');

        $solo = $this->makeWorkspace($owner, 'Solo books');
        $shared = $this->makeWorkspace($owner, 'Shared books');
        $this->addMember($shared, $admin, WorkspaceMember::ROLE_ADMIN);

        $soloTransaction = $this->recordExpense($solo, 35000);
        $sharedTransaction = $this->recordExpense($shared, 12500);

        $this->schedule($owner, daysAgo: 31, purgeAfterDaysFromNow: -1);

        $this->artisan('accounts:purge')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['id' => $owner->id]);

        // The workspace nobody else could run went with its owner.
        $this->assertDatabaseMissing('workspaces', ['id' => $solo->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $soloTransaction->id]);
        $this->assertDatabaseMissing('accounts', ['workspace_id' => $solo->id]);

        // The shared one has someone else's money in it and stays, under the
        // admin who is now its owner.
        $this->assertDatabaseHas('workspaces', ['id' => $shared->id, 'owner_id' => $admin->id]);
        $this->assertDatabaseHas('transactions', ['id' => $sharedTransaction->id]);
        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $shared->id,
            'user_id' => $admin->id,
            'role' => WorkspaceMember::ROLE_OWNER,
        ]);
        $this->assertDatabaseMissing('workspace_members', [
            'workspace_id' => $shared->id,
            'user_id' => $owner->id,
        ]);
    }

    #[Test]
    public function a_purge_leaves_nothing_of_the_erased_workspace_behind(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $workspace = $this->makeWorkspace($owner, 'Solo books');
        $this->recordExpense($workspace, 35000);

        $this->schedule($owner, daysAgo: 31, purgeAfterDaysFromNow: -1);

        $this->artisan('accounts:purge')->assertExitCode(0);

        foreach (['accounts', 'categories', 'transactions', 'entries', 'workspace_members'] as $table) {
            $this->assertDatabaseMissing($table, ['workspace_id' => $workspace->id]);
        }
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $due = $this->makeUser('due@example.test');
        $this->schedule($due, daysAgo: 31, purgeAfterDaysFromNow: -1);

        $this->artisan('accounts:purge', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $due->id]);
    }

    private function schedule(User $user, int $daysAgo, int $purgeAfterDaysFromNow): void
    {
        $user->forceFill([
            'deletion_requested_at' => now()->subDays($daysAgo),
            'deletion_purge_after' => now()->addDays($purgeAfterDaysFromNow),
        ])->save();
    }

    private function recordExpense(Workspace $workspace, int $minorUnits): Transaction
    {
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 1000000);

        return $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => $minorUnits,
            'currency' => 'TRY',
            'description' => 'Rent',
        ]));
    }
}
