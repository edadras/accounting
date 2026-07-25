<?php

declare(strict_types=1);

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Core\Actions\ManageMembership;
use Modules\Core\Http\Concerns\ResolvesCurrentUser;
use Modules\Core\Models\WorkspaceInvitation;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;

final class MemberController
{
    use ResolvesCurrentUser;

    public function index(Request $request, WorkspaceContext $context): JsonResponse
    {
        $this->assertCanManage($request);

        $members = WorkspaceMember::query()
            ->where('workspace_id', $context->require()->id)
            ->with('user:id,name,email')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $members->map(fn (WorkspaceMember $member) => [
                'id' => $member->id,
                'role' => $member->role,
                'joined_at' => $member->joined_at?->toIso8601String(),
                'user' => [
                    'id' => $member->user?->id,
                    'name' => $member->user?->name,
                    'email' => $member->user?->email,
                ],
            ])->all(),
        ]);
    }

    public function invite(
        Request $request,
        WorkspaceContext $context,
        ManageMembership $membership,
    ): JsonResponse {
        $this->assertCanManage($request);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in(WorkspaceMember::ROLES)],
        ]);

        ['invitation' => $invitation, 'token' => $token] = $membership->invite(
            workspace: $context->require(),
            email: $data['email'],
            role: $data['role'],
            invitedBy: $this->currentUser($request),
        );

        return response()->json([
            'data' => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status(),
                'expires_at' => $invitation->expires_at->toIso8601String(),

                // Shown once and never again — the hash is all that is stored.
                'token' => $token,
            ],
        ], 201);
    }

    public function invitations(Request $request, WorkspaceContext $context): JsonResponse
    {
        $this->assertCanManage($request);

        $invitations = WorkspaceInvitation::query()
            ->where('workspace_id', $context->require()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $invitations->map(fn (WorkspaceInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'status' => $invitation->status(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'created_at' => $invitation->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function revoke(
        Request $request,
        WorkspaceContext $context,
        ManageMembership $membership,
        string $id,
    ): JsonResponse {
        $this->assertCanManage($request);

        $invitation = WorkspaceInvitation::query()
            ->where('workspace_id', $context->require()->id)
            ->findOrFail($id);

        $membership->revoke($invitation);

        return response()->json(status: 204);
    }

    /**
     * Accepting happens before membership exists, so this route sits outside
     * the workspace middleware and identifies the workspace from the token.
     */
    public function accept(
        Request $request,
        ManageMembership $membership,
        string $token,
    ): JsonResponse {
        $member = $membership->accept($token, $this->currentUser($request));

        return response()->json([
            'data' => [
                'workspace_id' => $member->workspace_id,
                'role' => $member->role,
            ],
        ], 201);
    }

    public function updateRole(
        Request $request,
        WorkspaceContext $context,
        ManageMembership $membership,
        string $id,
    ): JsonResponse {
        $this->assertCanManage($request);

        $data = $request->validate([
            'role' => ['required', Rule::in(WorkspaceMember::ROLES)],
        ]);

        $member = WorkspaceMember::query()
            ->where('workspace_id', $context->require()->id)
            ->findOrFail($id);

        $membership->changeRole($member, $data['role']);

        return response()->json([
            'data' => ['id' => $member->id, 'role' => $member->role],
        ]);
    }

    public function destroy(
        Request $request,
        WorkspaceContext $context,
        ManageMembership $membership,
        string $id,
    ): JsonResponse {
        $this->assertCanManage($request);

        $member = WorkspaceMember::query()
            ->where('workspace_id', $context->require()->id)
            ->findOrFail($id);

        $membership->remove($member);

        return response()->json(status: 204);
    }

    private function assertCanManage(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless(
            $member instanceof WorkspaceMember && $member->canManageMembers(),
            403,
        );
    }
}
