<?php

declare(strict_types=1);

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads X-Workspace-Id, verifies the caller is actually a member, and only then
 * makes it the active workspace.
 *
 * Membership is checked here, once, rather than in every controller — the
 * failure mode of the alternative is one forgotten check exposing another
 * user's books.
 */
final class ResolveWorkspace
{
    public const HEADER = 'X-Workspace-Id';

    public function __construct(private readonly WorkspaceContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $request->header(self::HEADER);

        if (blank($workspaceId)) {
            return $this->refuse('workspace_required', 'Send the X-Workspace-Id header.', 400);
        }

        $user = $request->user();

        if ($user === null) {
            return $this->refuse('unauthenticated', 'Authentication required.', 401);
        }

        $workspace = Workspace::query()->find($workspaceId);

        if ($workspace === null) {
            // Deliberately the same answer as "you are not a member": a
            // different one would let a caller probe which workspace ids exist.
            return $this->refuse('workspace_forbidden', 'No access to this workspace.', 403);
        }

        $member = $workspace->memberFor($user);

        if ($member === null) {
            return $this->refuse('workspace_forbidden', 'No access to this workspace.', 403);
        }

        $this->context->set($workspace);
        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('workspace_member', $member);

        return $next($request);
    }

    private function refuse(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'request_id' => request()->header('X-Request-Id'),
            ],
        ], $status);
    }
}
