<?php

declare(strict_types=1);

namespace Modules\Search\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Workspace;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Support\SearchIndexer;

/**
 * Backfills the search index.
 *
 * The index is kept in step by model events, which only exist from the moment
 * the module is installed: every row written before that — and every row
 * written by a raw query, an import, or a seeder that bypassed Eloquent — is
 * invisible to search until this command has walked it.
 *
 * Safe to run at any time and as often as you like: each row is written by its
 * (indexable_type, indexable_id) identity, so re-running updates rows in place
 * rather than duplicating them.
 */
final class ReindexCommand extends Command
{
    protected $signature = 'search:reindex
        {--workspace= : Only reindex rows belonging to this workspace id}
        {--type= : Only reindex these types, comma-separated (transactions, documents, categories)}';

    protected $description = 'Rebuild the search index from the records it covers';

    /** Rows per query. Large enough to be few queries, small enough to fit in memory. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $types = $this->resolveTypes();

        if ($types === null) {
            return self::FAILURE;
        }

        $workspaceId = $this->option('workspace');

        if ($workspaceId !== null && ! Workspace::query()->whereKey($workspaceId)->exists()) {
            $this->error("Workspace [{$workspaceId}] does not exist.");

            return self::FAILURE;
        }

        $rows = [];
        $total = 0;

        foreach ($types as $type) {
            $indexed = $this->reindexType($type, $workspaceId === null ? null : (string) $workspaceId);

            $rows[] = [$type, $indexed];
            $total += $indexed;
        }

        $this->table(['type', 'indexed'], $rows);
        $this->info("Reindexed {$total} record(s).");

        return self::SUCCESS;
    }

    private function reindexType(string $type, ?string $workspaceId): int
    {
        [$class] = SearchIndexer::sources()[$type];

        $indexed = 0;

        $class::query()
            // Console runs have no active workspace, so the global scope would
            // otherwise fail closed and reindex nothing at all.
            ->withoutWorkspaceScope()
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->whereNotNull('workspace_id')
            ->chunkById(self::CHUNK, function (Collection $models) use (&$indexed): void {
                foreach ($models as $model) {
                    /** @var Model $model */
                    SearchIndexer::index($model);
                    $indexed++;
                }
            });

        return $indexed;
    }

    /** @return list<string>|null null when the caller named a type that does not exist */
    private function resolveTypes(): ?array
    {
        $requested = $this->option('type');

        if ($requested === null || trim((string) $requested) === '') {
            return SearchEngine::TYPES;
        }

        $types = array_values(array_filter(array_map('trim', explode(',', (string) $requested))));
        $unknown = array_diff($types, SearchEngine::TYPES);

        if ($unknown !== []) {
            $this->error(
                'Unknown search type(s): '.implode(', ', $unknown)
                .'. Known types: '.implode(', ', SearchEngine::TYPES).'.'
            );

            return null;
        }

        return $types;
    }
}
