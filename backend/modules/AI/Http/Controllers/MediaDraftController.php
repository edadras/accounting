<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Jobs\ExtractReceiptJob;
use Modules\AI\Jobs\TranscribeVoiceNoteJob;
use Modules\Core\Models\WorkspaceMember;
use Modules\Core\Support\WorkspaceContext;
use Modules\Documents\Models\Document;

/**
 * Hands an already-uploaded document to the queue.
 *
 * The file is never taken from the request body here — it is a Document the
 * user uploaded through the Documents module, looked up through the workspace
 * scope. A path arriving from a client is a path a client can choose.
 */
final class MediaDraftController
{
    public function __construct(private readonly WorkspaceContext $context) {}

    public function receipt(Request $request): JsonResponse
    {
        return $this->queue($request, ExtractReceiptJob::class);
    }

    public function voiceNote(Request $request): JsonResponse
    {
        return $this->queue($request, TranscribeVoiceNoteJob::class);
    }

    /** @param class-string<ExtractReceiptJob|TranscribeVoiceNoteJob> $job */
    private function queue(Request $request, string $job): JsonResponse
    {
        $member = $request->attributes->get('workspace_member');
        abort_unless($member instanceof WorkspaceMember && $member->canWrite(), 403);

        $data = $request->validate([
            'document_id' => ['required', 'string', 'max:64'],
        ]);

        $document = Document::query()->findOrFail((string) $data['document_id']);
        $path = Storage::disk($document->disk)->path($document->path);

        if (! is_readable($path)) {
            throw AiException::fileNotReadable($path);
        }

        $job::dispatch(
            $this->context->require()->id,
            $path,
            $document->id,
            $request->user()?->id,
        );

        return response()->json([
            'data' => ['status' => 'queued', 'document_id' => $document->id],
        ], 202);
    }
}
