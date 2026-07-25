<?php

declare(strict_types=1);

namespace Modules\Recurring\Console;

use Illuminate\Console\Command;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Recurring\Actions\PostDueRecurring;

/**
 * The nightly job of docs/04-roadmap.md M1: post whatever the standing
 * instructions owe.
 *
 * Runs workspace by workspace because every domain query is scoped to one; a
 * cross-workspace sweep has no meaning and no safe query.
 */
final class PostDueRecurringCommand extends Command
{
    protected $signature = 'recurring:post
        {--workspace= : Only post rules belonging to this workspace id}
        {--at= : Post everything due as of this moment instead of now}';

    protected $description = 'Post the transactions that recurring rules owe';

    public function handle(WorkspaceContext $context, PostDueRecurring $poster): int
    {
        $at = $this->option('at') === null ? null : new \DateTimeImmutable((string) $this->option('at'));

        $workspaces = Workspace::query()
            ->when($this->option('workspace') !== null, fn ($query) => $query->whereKey($this->option('workspace')))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($workspaces as $workspace) {
            $posted = $context->runFor($workspace, fn (): array => $poster->handle($at));

            if ($posted === []) {
                continue;
            }

            $total += count($posted);

            $this->line(sprintf('%s (%s): %d posted', $workspace->name, $workspace->id, count($posted)));
        }

        $this->info("Posted {$total} recurring transaction(s) across {$workspaces->count()} workspace(s).");

        return self::SUCCESS;
    }
}
