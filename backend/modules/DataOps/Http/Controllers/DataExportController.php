<?php

declare(strict_types=1);

namespace Modules\DataOps\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Audit\Support\AuditRecorder;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;
use Modules\DataOps\Exceptions\DataOpsException;
use Modules\DataOps\Http\Resources\DataExportResource;
use Modules\DataOps\Jobs\BuildDataExportJob;
use Modules\DataOps\Models\DataExport;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The right to take your data out (docs/07-security.md §8).
 *
 * An export is a complete, offline, unscoped copy of a workspace, so it is
 * limited to the two roles that could delete the workspace anyway.
 */
final class DataExportController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeExport($request);

        $exports = DataExport::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(min((int) $request->query('per_page', 50), 200))
            ->get();

        return response()->json(['data' => DataExportResource::collection($exports)]);
    }

    public function store(Request $request, WorkspaceContext $context, AuditRecorder $audit): JsonResponse
    {
        $this->authorizeExport($request);

        $export = DataExport::query()->create([
            'workspace_id' => $context->require()->id,
            'requested_by' => $request->user()?->id,
            'status' => DataExport::STATUS_PENDING,
            'format' => DataExport::FORMAT_ZIP,
        ]);

        $audit->record(
            action: 'data_export.requested',
            subject: $export,
            after: ['format' => $export->format],
        );

        BuildDataExportJob::dispatch($export->id);

        return (new DataExportResource($export->refresh()))
            ->response()
            ->setStatusCode(202);
    }

    public function download(Request $request, string $id, AuditRecorder $audit): StreamedResponse
    {
        $this->authorizeExport($request);

        $export = DataExport::query()->findOrFail($id);

        if ($export->hasExpired()) {
            throw DataOpsException::exportExpired();
        }

        if (! $export->isDownloadable()) {
            throw DataOpsException::exportNotReady($export->status);
        }

        // Who took a copy of the books, and when. This is the row a breach
        // investigation needs most.
        $audit->record(
            action: 'data_export.downloaded',
            subject: $export,
            after: ['size' => $export->size],
        );

        return Storage::disk((string) $export->disk)->download($export->path, $export->filename());
    }

    private function authorizeExport(Request $request): WorkspaceMember
    {
        $member = $request->attributes->get('workspace_member');

        $allowed = $member instanceof WorkspaceMember && in_array(
            $member->role,
            [WorkspaceMember::ROLE_OWNER, WorkspaceMember::ROLE_ADMIN],
            true,
        );

        if (! $allowed) {
            throw DataOpsException::exportForbidden();
        }

        return $member;
    }
}
