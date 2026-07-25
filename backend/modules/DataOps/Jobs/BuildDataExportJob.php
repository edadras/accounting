<?php

declare(strict_types=1);

namespace Modules\DataOps\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\DataOps\Actions\BuildWorkspaceExport;
use Modules\DataOps\Models\DataExport;

/**
 * Builds one export off the request thread.
 *
 * It carries the id rather than the model: SerializesModels would re-query the
 * row in the worker, where no workspace is active and the global scope
 * therefore finds nothing.
 */
final class BuildDataExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly string $exportId) {}

    public function handle(WorkspaceContext $context, BuildWorkspaceExport $builder): void
    {
        $export = DataExport::query()->withoutGlobalScopes()->find($this->exportId);

        if ($export === null) {
            return;
        }

        $workspace = Workspace::query()->find($export->workspace_id);

        if ($workspace === null) {
            return;
        }

        $context->runFor($workspace, fn () => $builder->handle($export));
    }
}
