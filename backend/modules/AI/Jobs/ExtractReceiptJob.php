<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Actions\ExtractReceipt;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Models\AiDraft;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;

/**
 * OCR off the request thread.
 *
 * A queued job has no request and therefore no workspace, so it carries the id
 * and re-enters that scope explicitly. Without this the global scope would find
 * no active workspace and — because it fails closed — the job would quietly do
 * nothing at all.
 */
final class ExtractReceiptJob implements ShouldQueue
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

    public function handle(WorkspaceContext $context, ExtractReceipt $extract, StoreDraft $store): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $context->runFor($workspace, function () use ($extract, $store): void {
            $store->handle($extract->handle($this->path), [
                'source' => AiDraft::SOURCE_OCR,
                'document_id' => $this->documentId,
                'created_by' => $this->userId,
            ]);
        });
    }
}
