<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Modules\Audit\Models\AuditLog;
use Modules\Audit\Support\AuditRecorder;
use Modules\Ledger\Actions\RecordTransaction;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * The trail exists so that "who changed this number, and to what" always has an
 * answer. These tests pin the three properties that make it worth trusting: it
 * captures the change, it cannot be edited afterwards, and it never leaks a
 * secret or another workspace's activity.
 */
final class AuditLogTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function recording_a_transaction_writes_a_trail_entry(): void
    {
        $user = $this->makeUser('trail@example.test');
        $workspace = $this->makeWorkspace($user);
        $account = $this->makeAccount($workspace, openingBalance: 100000);

        $this->actingAs($user);

        $transaction = $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 35000,
            'currency' => 'TRY',
        ]));

        $entry = AuditLog::query()
            ->forWorkspace($workspace->id)
            ->where('action', 'transaction.created')
            ->where('subject_id', $transaction->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame(35000, $entry->after['amount']);
    }

    #[Test]
    public function an_update_stores_only_what_changed(): void
    {
        $user = $this->makeUser('change@example.test');
        $workspace = $this->makeWorkspace($user);
        $account = $this->makeAccount($workspace, 'Wallet', 'TRY', 100000);

        $this->actingAs($user);

        $this->inWorkspace($workspace, function () use ($account): void {
            $account->name = 'Renamed wallet';
            $account->save();
        });

        $entry = AuditLog::query()
            ->where('action', 'account.updated')
            ->where('subject_id', $account->id)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($entry);

        // Only the renamed field — writing the whole row every time would bury
        // the one thing a reviewer is looking for.
        $this->assertSame(['name'], array_keys($entry->after));
        $this->assertSame('Wallet', $entry->before['name']);
        $this->assertSame('Renamed wallet', $entry->after['name']);
    }

    #[Test]
    public function a_deletion_keeps_what_was_deleted(): void
    {
        $user = $this->makeUser('gone@example.test');
        $workspace = $this->makeWorkspace($user);
        $account = $this->makeAccount($workspace, openingBalance: 5000);

        $this->actingAs($user);

        $transaction = $this->inWorkspace($workspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $account->id,
            'amount' => 1200,
            'currency' => 'TRY',
        ]));

        $this->inWorkspace($workspace, fn () => $transaction->delete());

        $entry = AuditLog::query()
            ->where('action', 'transaction.deleted')
            ->where('subject_id', $transaction->id)
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(1200, $entry->before['amount']);
    }

    #[Test]
    public function the_trail_cannot_be_edited_or_deleted(): void
    {
        $user = $this->makeUser('immutable@example.test');
        $workspace = $this->makeWorkspace($user);

        $this->actingAs($user);
        $entry = app(AuditRecorder::class)->record('test.event', workspaceId: $workspace->id);

        $this->assertNotNull($entry);

        try {
            $entry->update(['action' => 'tampered']);
            $this->fail('An audit entry must not be updatable.');
        } catch (LogicException) {
            // expected
        }

        try {
            $entry->delete();
            $this->fail('An audit entry must not be deletable.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame('test.event', $entry->fresh()->action);
    }

    #[Test]
    public function secrets_never_enter_the_trail(): void
    {
        $recorder = app(AuditRecorder::class);

        $cleaned = $recorder->clean([
            'amount' => 1000,
            'password' => 'hunter2',
            'two_factor_secret' => 'ABCDEF',
            'card_number' => '4111111111111111',
            'updated_at' => 'noise',
        ]);

        $this->assertSame(1000, $cleaned['amount']);
        $this->assertSame('[redacted]', $cleaned['password']);
        $this->assertSame('[redacted]', $cleaned['two_factor_secret']);
        $this->assertSame('[redacted]', $cleaned['card_number']);
        $this->assertArrayNotHasKey('updated_at', $cleaned);
    }

    #[Test]
    public function seeding_a_new_workspace_does_not_flood_the_trail(): void
    {
        $user = $this->makeUser('quiet@example.test');
        $this->actingAs($user);

        $workspace = $this->makeWorkspace($user);

        $entries = AuditLog::query()->forWorkspace($workspace->id)->get();

        // The starter account and ~30 seeded categories are not something a
        // person did, and would bury the first real entry.
        $this->assertSame([], $entries->pluck('action')
            ->filter(fn (string $action) => str_starts_with($action, 'account.')
                || str_starts_with($action, 'category.'))
            ->values()
            ->all());

        // The owner becoming a member, however, is a real access event and is
        // exactly what this log is for.
        $this->assertSame(['workspace_member.created'], $entries->pluck('action')->all());
    }

    #[Test]
    public function a_failed_sign_in_is_recorded(): void
    {
        $this->makeUser('victim@example.test');

        $this->postJson('/api/v1/auth/login', [
            'email' => 'victim@example.test',
            'password' => 'wrong-password',
        ])->assertUnauthorized();

        $this->assertSame(
            1,
            AuditLog::query()->where('action', 'auth.login_failed')->count(),
        );
    }

    #[Test]
    public function only_an_owner_or_admin_can_read_the_trail(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $workspace = $this->makeWorkspace($owner);

        $member = $this->makeUser('member@example.test');
        $workspace->members()->create([
            'user_id' => $member->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/audit-logs', ['X-Workspace-Id' => $workspace->id])
            ->assertForbidden();

        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/audit-logs', ['X-Workspace-Id' => $workspace->id])
            ->assertOk();
    }

    #[Test]
    public function the_trail_never_shows_another_workspaces_activity(): void
    {
        $victim = $this->makeUser('victim2@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim');
        $victimAccount = $this->makeAccount($victimWorkspace, openingBalance: 90000);

        $this->actingAs($victim);
        $this->inWorkspace($victimWorkspace, fn () => app(RecordTransaction::class)->handle([
            'type' => 'expense',
            'account_id' => $victimAccount->id,
            'amount' => 4242,
            'currency' => 'TRY',
        ]));

        $intruder = $this->makeUser('intruder2@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder');

        Sanctum::actingAs($intruder);

        $response = $this->getJson('/api/v1/audit-logs', [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertOk();

        foreach ($response->json('data') as $row) {
            $this->assertNotSame('4242', (string) ($row['after']['amount'] ?? ''));
        }

        $this->getJson('/api/v1/audit-logs', ['X-Workspace-Id' => $victimWorkspace->id])
            ->assertForbidden();
    }
}
