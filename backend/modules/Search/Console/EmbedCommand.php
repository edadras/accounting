<?php

declare(strict_types=1);

namespace Modules\Search\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Modules\AI\Contracts\EmbeddingProvider;
use Modules\AI\Models\Embedding;
use Modules\Core\Models\Workspace;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Models\SearchEntry;
use Modules\Search\Support\EmbeddingIndexer;

/**
 * Backfills the vectors semantic search ranks by.
 *
 * Three kinds of row need it: everything written before the feature existed,
 * everything written while a remote embedding provider was configured (those
 * are deliberately not embedded inline — see EmbeddingIndexer), and everything
 * at all after the embedding model changes, since vectors from two models are
 * not comparable.
 *
 * Idempotent by construction rather than by care: a row is embedded when it
 * has no vector for the *current* model, so a second run finds nothing to do
 * and a switch of model re-embeds everything without touching the old vectors.
 * `--force` is for the case the identity cannot see — the same model, but the
 * text behind it changed outside Eloquent.
 */
final class EmbedCommand extends Command
{
    protected $signature = 'search:embed
        {--workspace= : Only embed rows belonging to this workspace id}
        {--type= : Only embed these types, comma-separated (transactions, documents, categories)}
        {--force : Recompute vectors that already exist for the current model}';

    protected $description = 'Compute the missing semantic-search embeddings for indexed records';

    /** Rows per query. Also the batch handed to the provider in one call. */
    private const CHUNK = 200;

    public function handle(EmbeddingProvider $provider): int
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

        $model = $provider->name();
        $rows = [];
        $embedded = 0;
        $skipped = 0;

        foreach ($types as $type) {
            $outcome = $this->embedType($provider, $model, $type, $workspaceId === null ? null : (string) $workspaceId);

            $rows[] = [$type, $outcome['embedded'], $outcome['skipped']];
            $embedded += $outcome['embedded'];
            $skipped += $outcome['skipped'];
        }

        $this->table(['type', 'embedded', 'already up to date'], $rows);
        $this->info("Embedded {$embedded} record(s) with [{$model}]; {$skipped} already had a vector.");

        return self::SUCCESS;
    }

    /** @return array{embedded: int, skipped: int} */
    private function embedType(EmbeddingProvider $provider, string $model, string $type, ?string $workspaceId): array
    {
        $embedded = 0;
        $skipped = 0;

        SearchEntry::query()
            // Console runs have no active workspace, so the global scope would
            // otherwise fail closed and embed nothing at all.
            ->withoutWorkspaceScope()
            ->where('type', $type)
            ->when($workspaceId !== null, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->orderBy('id')
            ->chunk(self::CHUNK, function (Collection $entries) use ($provider, $model, &$embedded, &$skipped): void {
                $existing = $this->existingFor($entries, $model);

                $pending = $entries->reject(function (SearchEntry $entry) use ($existing): bool {
                    return ! $this->option('force')
                        && isset($existing[$entry->indexable_type.'|'.$entry->indexable_id]);
                })->values();

                $skipped += $entries->count() - $pending->count();

                if ($pending->isEmpty()) {
                    return;
                }

                // One provider call per chunk rather than per row: with a
                // remote model that is the difference between a backfill that
                // finishes and one that is still going tomorrow.
                $vectors = $provider->embedBatch($pending->pluck('content')->map(strval(...))->all());

                foreach ($pending as $index => $entry) {
                    EmbeddingIndexer::store(
                        (string) $entry->workspace_id,
                        (string) $entry->indexable_type,
                        (string) $entry->indexable_id,
                        $model,
                        $vectors[$index] ?? [],
                    );

                    $embedded++;
                }
            });

        return ['embedded' => $embedded, 'skipped' => $skipped];
    }

    /**
     * @param  Collection<int, SearchEntry>  $entries
     * @return array<string, true>
     */
    private function existingFor(Collection $entries, string $model): array
    {
        return Embedding::query()
            ->withoutWorkspaceScope()
            ->forModel($model)
            ->whereIn('owner_id', $entries->pluck('indexable_id')->all())
            ->get(['owner_type', 'owner_id'])
            ->mapWithKeys(fn (Embedding $row): array => [$row->owner_type.'|'.$row->owner_id => true])
            ->all();
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
