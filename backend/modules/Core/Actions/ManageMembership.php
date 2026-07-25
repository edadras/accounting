<?php

declare(strict_types=1);

namespace Modules\Core\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Exceptions\MembershipException;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceInvitation;
use Modules\Core\Models\WorkspaceMember;

/**
 * Invitations and role changes.
 *
 * The shape of a workspace's access list is exactly what an attacker would want
 * to change quietly, so every method here writes to the audit trail, and the
 * owner is protected from removal and from demotion — otherwise an admin could
 * lock the owner out of their own books.
 */
final readonly class ManageMembership
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * @return array{invitation: WorkspaceInvitation, token: string}
     *
     * The plaintext token is returned once, here. It is never stored and never
     * retrievable again — a lost invitation is re-issued, not recovered.
     */
    public function invite(
        Workspace $workspace,
        string $email,
        string $role,
        ?User $invitedBy = null,
        int $expiresInDays = 14,
    ): array {
        $email = mb_strtolower(trim($email));

        if ($role === WorkspaceMember::ROLE_OWNER) {
            throw MembershipException::cannotInviteOwnerRole();
        }

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null && $workspace->memberFor($existing) !== null) {
            throw MembershipException::alreadyMember($email);
        }

        $pending = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->where('email', $email)
            ->pending()
            ->exists();

        if ($pending) {
            throw MembershipException::alreadyInvited($email);
        }

        $token = WorkspaceInvitation::newToken();

        $invitation = WorkspaceInvitation::query()->create([
            'workspace_id' => $workspace->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => WorkspaceInvitation::hashToken($token),
            'invited_by' => $invitedBy?->id,
            'expires_at' => now()->addDays($expiresInDays),
        ]);

        $this->audit->record(
            action: 'workspace.member_invited',
            subject: $invitation,
            after: ['email' => $email, 'role' => $role],
            workspaceId: $workspace->id,
        );

        return ['invitation' => $invitation, 'token' => $token];
    }

    public function accept(string $token, User $user): WorkspaceMember
    {
        $invitation = WorkspaceInvitation::query()
            ->where('token_hash', WorkspaceInvitation::hashToken($token))
            ->first();

        if ($invitation === null || ! $invitation->isPending()) {
            throw MembershipException::invitationNotFound();
        }

        if ($invitation->email !== mb_strtolower($user->email)) {
            // The token alone is not enough. Otherwise anyone who intercepted a
            // link could join a stranger's books.
            throw MembershipException::invitationNotForYou();
        }

        return DB::transaction(function () use ($invitation, $user): WorkspaceMember {
            $existing = WorkspaceMember::query()
                ->where('workspace_id', $invitation->workspace_id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing !== null) {
                $invitation->forceFill(['accepted_at' => now()])->save();

                return $existing;
            }

            $member = WorkspaceMember::query()->create([
                'workspace_id' => $invitation->workspace_id,
                'user_id' => $user->id,
                'role' => $invitation->role,
                'joined_at' => now(),
            ]);

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record(
                action: 'workspace.member_joined',
                subject: $member,
                after: ['user_id' => $user->id, 'role' => $invitation->role],
                workspaceId: $invitation->workspace_id,
            );

            return $member;
        });
    }

    public function revoke(WorkspaceInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            throw MembershipException::invitationNotFound();
        }

        $invitation->forceFill(['revoked_at' => now()])->save();

        $this->audit->record(
            action: 'workspace.invitation_revoked',
            subject: $invitation,
            before: ['email' => $invitation->email, 'role' => $invitation->role],
            workspaceId: $invitation->workspace_id,
        );
    }

    public function changeRole(WorkspaceMember $member, string $role): WorkspaceMember
    {
        if ($member->role === WorkspaceMember::ROLE_OWNER) {
            throw MembershipException::cannotChangeOwnerRole();
        }

        if ($role === WorkspaceMember::ROLE_OWNER) {
            throw MembershipException::cannotInviteOwnerRole();
        }

        // The Auditable trait on WorkspaceMember records the before/after here.
        $member->forceFill(['role' => $role])->save();

        return $member->refresh();
    }

    public function remove(WorkspaceMember $member): void
    {
        if ($member->role === WorkspaceMember::ROLE_OWNER) {
            throw MembershipException::cannotRemoveOwner();
        }

        $member->delete();
    }
}
