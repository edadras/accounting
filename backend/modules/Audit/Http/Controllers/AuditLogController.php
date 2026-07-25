<?php

declare(strict_types=1);

namespace Modules\Audit\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Audit\Models\AuditLog;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;

final class AuditLogController
{
    /** The workspace's trail. Owner and admin only — it exposes what everyone did. */
    public function index(Request $request, WorkspaceContext $context): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');

        abort_unless(
            $member instanceof WorkspaceMember && $member->canManageMembers(),
            403,
        );

        $query = AuditLog::query()
            ->forWorkspace($context->require()->id)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }

        if ($type = $request->query('subject_type')) {
            $query->where('subject_type', $type);
        }

        if ($subjectId = $request->query('subject_id')) {
            $query->where('subject_id', $subjectId);
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        if ($from = $request->query('from')) {
            $query->where('created_at', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->where('created_at', '<=', $to);
        }

        $page = $query->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => array_map($this->present(...), $page->items()),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * The caller's own security log: sign-ins and sign-outs, which carry no
     * workspace and so never appear in the trail above.
     */
    public function personal(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->forUser($request->user()->id)
            ->whereNull('workspace_id')
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json([
            'data' => $logs->map($this->present(...))->all(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'before' => $log->before,
            'after' => $log->after,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
            'created_at' => $log->created_at?->toIso8601String(),
            'user' => $log->relationLoaded('user') && $log->user !== null
                ? ['id' => $log->user->id, 'name' => $log->user->name]
                : null,
        ];
    }
}
