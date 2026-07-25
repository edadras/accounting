<?php

declare(strict_types=1);

namespace Modules\Capture\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Capture\Actions\IssueIngestAlias;
use Modules\Capture\Http\Resources\IngestAliasResource;
use Modules\Capture\Models\IngestAlias;
use Modules\Core\Models\WorkspaceMember;

/**
 * The workspace's mail addresses.
 *
 * `store` is the only response that ever contains a usable address; afterwards
 * only the hash exists, so a forgotten address is reissued and the old one
 * revoked rather than recovered.
 */
final class IngestAliasController
{
    public function index(): JsonResponse
    {
        $aliases = IngestAlias::query()->orderByDesc('created_at')->get();

        return response()->json(['data' => IngestAliasResource::collection($aliases)]);
    }

    public function store(Request $request, IssueIngestAlias $issue): JsonResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $issued = $issue->handle($data['label'] ?? null, $request->user()?->id);

        return response()->json([
            'data' => array_merge(
                (new IngestAliasResource($issued['alias']))->toArray($request),
                // Shown once. There is no second call that can return it.
                ['address' => $issued['address']],
            ),
        ], 201);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->authorizeManage($request);

        $alias = IngestAlias::query()->findOrFail($id);
        $alias->forceFill(['revoked_at' => now()])->save();

        return response()->json(status: 204);
    }

    private function authorizeManage(Request $request): void
    {
        $member = $request->attributes->get('workspace_member');

        // Handing out an address that writes into these books is an
        // administrative act, not an everyday one.
        abort_unless($member instanceof WorkspaceMember && $member->canManageOthers(), 403);
    }
}
