<?php

declare(strict_types=1);

namespace Modules\DataOps\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Models\Workspace;
use Modules\Core\Models\WorkspaceMember;
use Modules\DataOps\Support\WorkspaceEraser;

/**
 * The irreversible half of account deletion (docs/07-security.md §8).
 *
 * The question every workspace the user belongs to has to answer first is
 * whether anyone is left who can run it. A personal workspace goes with its
 * owner; a shared one has other people's money in it, so it is handed to the
 * remaining owner or admin instead of being destroyed under them.
 */
final readonly class PurgeAccount
{
    public function __construct(
        private AuditRecorder $audit,
        private WorkspaceEraser $eraser,
    ) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $memberships = WorkspaceMember::query()->where('user_id', $user->id)->get();

            foreach ($memberships as $membership) {
                $workspace = Workspace::query()
                    ->withTrashed()
                    ->find($membership->workspace_id);

                if ($workspace === null) {
                    $membership->delete();

                    continue;
                }

                $successor = $this->successorFor($workspace, $user);

                if ($successor === null) {
                    $this->eraser->erase($workspace);

                    continue;
                }

                $this->handOver($workspace, $successor, $user);
            }

            $this->audit->record(
                action: 'account.purged',
                subject: $user,
                before: ['email' => $user->email, 'name' => $user->name],
            );

            $user->tokens()->delete();
            $user->delete();
        });
    }

    /** Whoever is left that may run the workspace, owners before admins. */
    private function successorFor(Workspace $workspace, User $leaving): ?WorkspaceMember
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', '!=', $leaving->id)
            ->whereIn('role', [WorkspaceMember::ROLE_OWNER, WorkspaceMember::ROLE_ADMIN])
            ->orderByRaw("CASE role WHEN 'owner' THEN 0 ELSE 1 END")
            ->orderBy('joined_at')
            ->first();
    }

    private function handOver(Workspace $workspace, WorkspaceMember $successor, User $leaving): void
    {
        if ($workspace->owner_id === $leaving->id) {
            // `workspaces.owner_id` cascades on delete, so a workspace still
            // pointing at the purged user would be dropped by the database the
            // moment the user row goes — taking everyone else's books with it.
            $workspace->forceFill(['owner_id' => $successor->user_id])->save();

            if ($successor->role !== WorkspaceMember::ROLE_OWNER) {
                $successor->forceFill(['role' => WorkspaceMember::ROLE_OWNER])->save();
            }

            $this->audit->record(
                action: 'workspace.ownership_transferred',
                subject: $workspace,
                before: ['owner_id' => $leaving->id],
                after: ['owner_id' => $successor->user_id],
                workspaceId: $workspace->id,
            );
        }

        WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $leaving->id)
            ->delete();
    }
}
