<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Models\WorkspaceInvitation;
use Modules\Core\Models\WorkspaceMember;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LedgerTestCase;

/**
 * Sharing a workspace is the feature that turns this from a personal ledger
 * into a family, company or building one — and it is also the surface where a
 * mistake hands someone else's books to a stranger.
 */
final class MembershipTest extends LedgerTestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_owner_can_invite_and_the_invitee_can_accept(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $workspace = $this->makeWorkspace($owner);
        $guest = $this->makeUser('guest@example.test');

        Sanctum::actingAs($owner);

        $token = $this->postJson('/api/v1/invitations', [
            'email' => 'guest@example.test',
            'role' => 'member',
        ], ['X-Workspace-Id' => $workspace->id])
            ->assertCreated()
            ->json('data.token');

        $this->assertNotEmpty($token);

        Sanctum::actingAs($guest);

        $this->postJson("/api/v1/invitations/{$token}/accept")
            ->assertCreated()
            ->assertJsonPath('data.workspace_id', $workspace->id)
            ->assertJsonPath('data.role', 'member');

        $this->assertTrue($guest->refresh()->isMemberOf($workspace));

        // And the newly joined member can now actually reach the books.
        $this->getJson('/api/v1/transactions', ['X-Workspace-Id' => $workspace->id])
            ->assertOk();
    }

    #[Test]
    public function the_plaintext_token_is_never_stored(): void
    {
        $owner = $this->makeUser('owner2@example.test');
        $workspace = $this->makeWorkspace($owner);

        Sanctum::actingAs($owner);

        $token = $this->postJson('/api/v1/invitations', [
            'email' => 'someone@example.test',
            'role' => 'viewer',
        ], ['X-Workspace-Id' => $workspace->id])->json('data.token');

        // A leaked database must not hand anyone a working link.
        $this->assertDatabaseMissing('workspace_invitations', ['token_hash' => $token]);
        $this->assertDatabaseHas('workspace_invitations', [
            'token_hash' => WorkspaceInvitation::hashToken($token),
        ]);
    }

    #[Test]
    public function an_invitation_cannot_be_accepted_by_a_different_email(): void
    {
        $owner = $this->makeUser('owner3@example.test');
        $workspace = $this->makeWorkspace($owner);
        $this->makeUser('intended@example.test');
        $interceptor = $this->makeUser('interceptor@example.test');

        Sanctum::actingAs($owner);
        $token = $this->postJson('/api/v1/invitations', [
            'email' => 'intended@example.test',
            'role' => 'member',
        ], ['X-Workspace-Id' => $workspace->id])->json('data.token');

        // Holding the link is not enough; otherwise anyone who intercepted it
        // could walk into a stranger's books.
        Sanctum::actingAs($interceptor);
        $this->postJson("/api/v1/invitations/{$token}/accept")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'invitation_email_mismatch');

        $this->assertFalse($interceptor->refresh()->isMemberOf($workspace));
    }

    #[Test]
    public function a_revoked_or_expired_invitation_cannot_be_accepted(): void
    {
        $owner = $this->makeUser('owner4@example.test');
        $workspace = $this->makeWorkspace($owner);
        $guest = $this->makeUser('late@example.test');

        Sanctum::actingAs($owner);

        $revoked = $this->postJson('/api/v1/invitations', [
            'email' => 'late@example.test',
            'role' => 'member',
        ], ['X-Workspace-Id' => $workspace->id]);

        $this->deleteJson(
            "/api/v1/invitations/{$revoked->json('data.id')}",
            headers: ['X-Workspace-Id' => $workspace->id],
        )->assertNoContent();

        Sanctum::actingAs($guest);
        $this->postJson("/api/v1/invitations/{$revoked->json('data.token')}/accept")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'invitation_invalid');

        // Expiry closes the same door.
        Sanctum::actingAs($owner);
        $second = $this->postJson('/api/v1/invitations', [
            'email' => 'late@example.test',
            'role' => 'member',
        ], ['X-Workspace-Id' => $workspace->id]);

        $secondId = $second->json('data.id');
        $this->assertIsString($secondId);

        WorkspaceInvitation::query()
            ->findOrFail($secondId)
            ->forceFill(['expires_at' => now()->subDay()])
            ->save();

        Sanctum::actingAs($guest);
        $this->postJson("/api/v1/invitations/{$second->json('data.token')}/accept")
            ->assertNotFound();

        $this->assertFalse($guest->refresh()->isMemberOf($workspace));
    }

    #[Test]
    public function an_unknown_token_answers_the_same_as_a_revoked_one(): void
    {
        // A different answer would let someone probe which tokens exist.
        $guest = $this->makeUser('prober@example.test');
        Sanctum::actingAs($guest);

        $this->postJson('/api/v1/invitations/'.str_repeat('x', 48).'/accept')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'invitation_invalid');
    }

    #[Test]
    public function a_plain_member_cannot_invite_anyone(): void
    {
        $owner = $this->makeUser('owner5@example.test');
        $workspace = $this->makeWorkspace($owner);

        $member = $this->makeUser('plain@example.test');
        $workspace->members()->create([
            'user_id' => $member->id,
            'role' => 'member',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/invitations', [
            'email' => 'stranger@example.test',
            'role' => 'admin',
        ], ['X-Workspace-Id' => $workspace->id])->assertForbidden();

        $this->getJson('/api/v1/members', ['X-Workspace-Id' => $workspace->id])
            ->assertForbidden();
    }

    #[Test]
    public function inviting_an_existing_member_or_inviting_twice_is_refused(): void
    {
        $owner = $this->makeUser('owner6@example.test');
        $workspace = $this->makeWorkspace($owner);
        $guest = $this->makeUser('dup@example.test');

        Sanctum::actingAs($owner);
        $headers = ['X-Workspace-Id' => $workspace->id];

        $this->postJson('/api/v1/invitations', [
            'email' => 'dup@example.test', 'role' => 'member',
        ], $headers)->assertCreated();

        $this->postJson('/api/v1/invitations', [
            'email' => 'dup@example.test', 'role' => 'member',
        ], $headers)->assertStatus(409)->assertJsonPath('error.code', 'already_invited');

        $workspace->members()->create([
            'user_id' => $guest->id, 'role' => 'member', 'joined_at' => now(),
        ]);

        $this->postJson('/api/v1/invitations', [
            'email' => 'dup@example.test', 'role' => 'viewer',
        ], $headers)->assertStatus(409)->assertJsonPath('error.code', 'already_member');
    }

    #[Test]
    public function the_owner_cannot_be_demoted_or_removed(): void
    {
        $owner = $this->makeUser('owner7@example.test');
        $workspace = $this->makeWorkspace($owner);

        $admin = $this->makeUser('admin@example.test');
        $workspace->members()->create([
            'user_id' => $admin->id, 'role' => 'admin', 'joined_at' => now(),
        ]);

        $ownerMember = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        Sanctum::actingAs($admin);
        $headers = ['X-Workspace-Id' => $workspace->id];

        // Otherwise an admin could lock the owner out of their own books.
        $this->patchJson("/api/v1/members/{$ownerMember->id}", ['role' => 'viewer'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'cannot_change_owner_role');

        $this->deleteJson("/api/v1/members/{$ownerMember->id}", headers: $headers)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'cannot_remove_owner');

        $this->assertSame('owner', $ownerMember->refresh()->role);
    }

    #[Test]
    public function nobody_can_be_invited_as_a_second_owner(): void
    {
        $owner = $this->makeUser('owner8@example.test');
        $workspace = $this->makeWorkspace($owner);

        Sanctum::actingAs($owner);

        $this->postJson('/api/v1/invitations', [
            'email' => 'usurper@example.test',
            'role' => 'owner',
        ], ['X-Workspace-Id' => $workspace->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'cannot_invite_as_owner');
    }

    #[Test]
    public function removing_a_member_takes_their_access_away_immediately(): void
    {
        $owner = $this->makeUser('owner9@example.test');
        $workspace = $this->makeWorkspace($owner);

        $member = $this->makeUser('leaving@example.test');
        $membership = $workspace->members()->create([
            'user_id' => $member->id, 'role' => 'member', 'joined_at' => now(),
        ]);

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/transactions', ['X-Workspace-Id' => $workspace->id])
            ->assertOk();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/members/{$membership->id}", headers: [
            'X-Workspace-Id' => $workspace->id,
        ])->assertNoContent();

        Sanctum::actingAs($member);
        $this->getJson('/api/v1/transactions', ['X-Workspace-Id' => $workspace->id])
            ->assertForbidden();
    }

    #[Test]
    public function membership_changes_land_in_the_audit_trail(): void
    {
        $owner = $this->makeUser('owner10@example.test');
        $workspace = $this->makeWorkspace($owner);
        $guest = $this->makeUser('audited@example.test');

        Sanctum::actingAs($owner);
        $token = $this->postJson('/api/v1/invitations', [
            'email' => 'audited@example.test', 'role' => 'member',
        ], ['X-Workspace-Id' => $workspace->id])->json('data.token');

        Sanctum::actingAs($guest);
        $this->postJson("/api/v1/invitations/{$token}/accept")->assertCreated();

        $actions = AuditLog::query()
            ->forWorkspace($workspace->id)
            ->pluck('action')
            ->all();

        $this->assertContains('workspace.member_invited', $actions);
        $this->assertContains('workspace.member_joined', $actions);
    }

    #[Test]
    public function an_invitation_from_another_workspace_cannot_be_revoked(): void
    {
        $victim = $this->makeUser('victimOwner@example.test');
        $victimWorkspace = $this->makeWorkspace($victim, 'Victim');

        Sanctum::actingAs($victim);
        $invitationId = $this->postJson('/api/v1/invitations', [
            'email' => 'target@example.test', 'role' => 'member',
        ], ['X-Workspace-Id' => $victimWorkspace->id])->json('data.id');

        $intruder = $this->makeUser('intruderOwner@example.test');
        $intruderWorkspace = $this->makeWorkspace($intruder, 'Intruder');

        Sanctum::actingAs($intruder);
        $this->deleteJson("/api/v1/invitations/{$invitationId}", headers: [
            'X-Workspace-Id' => $intruderWorkspace->id,
        ])->assertNotFound();

        $this->assertIsString($invitationId);
        $this->assertNull(WorkspaceInvitation::query()->findOrFail($invitationId)->revoked_at);
    }
}
