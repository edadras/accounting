<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Actions\TranscribeVoiceNote;
use Modules\AI\Models\AiDraft;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;

/**
 * Normalise, transcribe, parse — all on the `media` queue, because ffmpeg and a
 * speech model together take far longer than a request may.
 */
final class TranscribeVoiceNoteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $path,
        public readonly ?string $documentId = null,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue('media');
    }

    public function handle(WorkspaceContext $context, TranscribeVoiceNote $transcribe, StoreDraft $store): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $context->runFor($workspace, function () use ($transcribe, $store): void {
            $draft = $transcribe->handle($this->path);

            $store->handle($draft, [
                'source' => AiDraft::SOURCE_VOICE,
                'input_text' => (string) ($draft->meta['transcript'] ?? ''),
                'document_id' => $this->documentId,
                'created_by' => $this->userId,
            ]);
        });
    }
}
