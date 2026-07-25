<?php

declare(strict_types=1);

namespace Modules\Reports\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Reports\Actions\RunReportExport;
use Modules\Reports\Models\ReportExport;
use Throwable;

/**
 * Builds an export off the request thread.
 *
 * A queued job has no request and therefore no workspace, so it carries the id
 * and re-enters that scope explicitly. Without this the global scope would find
 * no active workspace and — because it fails closed — the report would come
 * back empty and the user would get a file of nothing.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $exportId,
    ) {
        $this->onQueue((string) config('reports.exports.queue', 'exports'));
    }

    public function handle(WorkspaceContext $context, RunReportExport $run): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        $context->runFor($workspace, function () use ($run): void {
            $export = ReportExport::query()->find($this->exportId);

            if ($export === null) {
                return;
            }

            $run->handle($export);
        });
    }

    /**
     * The action already marked the row failed; this covers the case where the
     * job never got that far — a lost workspace, a dead queue worker, a retry
     * that ran out. Without it the export would poll as `processing` forever.
     */
    public function failed(?Throwable $failure): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);

        if ($workspace === null) {
            return;
        }

        app(WorkspaceContext::class)->runFor($workspace, function () use ($failure): void {
            ReportExport::query()
                ->whereKey($this->exportId)
                ->whereIn('status', [ReportExport::STATUS_PENDING, ReportExport::STATUS_PROCESSING])
                ->update([
                    'status' => ReportExport::STATUS_FAILED,
                    'error' => mb_substr($failure?->getMessage() ?? 'Export failed.', 0, 1000),
                    'completed_at' => now(),
                ]);
        });
    }
}
