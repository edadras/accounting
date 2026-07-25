<?php

declare(strict_types=1);

namespace Modules\Search\Engines;

use Meilisearch\Client;
use Modules\Core\Support\WorkspaceContext;
use Modules\Search\Contracts\WritableSearchEngine;
use Modules\Search\Models\SearchEntry;
use Modules\Search\Support\ResultHydrator;
use Modules\Search\Support\TextNormalizer;

/**
 * The engine docs/01 names for production: typo tolerance, prefix matching and
 * structured filters at a speed `LIKE '%…%'` will never reach.
 *
 * Two decisions worth stating.
 *
 * The documents pushed here hold the *normalised* text, the same string the
 * database engine matches against. Meilisearch has its own normalisation, but
 * it does not know that a Persian ی and an Arabic ي are the same letter to a
 * user of this product; folding before the push means one answer to that
 * question, shared by both engines and by the Flutter client.
 *
 * And the search returns ids, not records. Meilisearch is asked to filter by
 * workspace, but a document store outside the database is not where tenant
 * isolation should ultimately rest, so the ids are resolved through scoped
 * Eloquent queries afterwards. A stale or mis-filtered document then hydrates
 * to nothing instead of leaking a row.
 */
final class MeilisearchEngine implements WritableSearchEngine
{
    private ?Client $client = null;

    public function __construct(
        private readonly WorkspaceContext $context,
        private readonly ResultHydrator $hydrator,
    ) {}

    public function search(string $query, array $types = [], int $limit = self::DEFAULT_LIMIT): array
    {
        $types = $this->resolveTypes($types);
        $limit = max(1, $limit);

        $results = array_fill_keys($types, []);
        $needle = TextNormalizer::normalize($query);
        $workspaceId = $this->context->id();

        if ($needle === '' || $workspaceId === null) {
            return $results;
        }

        foreach ($types as $type) {
            $hits = $this->client()->index($this->indexName($type))->search($needle, [
                'filter' => 'workspace_id = "'.$workspaceId.'"',
                'limit' => $limit,
                'attributesToRetrieve' => ['indexable_id'],
            ])->getHits();

            $ids = array_values(array_filter(array_map(
                static fn (array $hit): string => (string) ($hit['indexable_id'] ?? ''),
                $hits,
            )));

            $results[$type] = $this->hydrator->hydrate($type, $ids);
        }

        return $results;
    }

    public function configure(): void
    {
        foreach (self::TYPES as $type) {
            $index = $this->indexName($type);

            $this->client()->createIndex($index, ['primaryKey' => 'id']);

            // Without this the workspace filter is rejected outright, which is
            // the right failure mode: a misconfigured index cannot silently
            // serve one tenant's rows to another.
            $this->client()->index($index)->updateFilterableAttributes(['workspace_id', 'type', 'indexable_id']);
        }
    }

    public function put(SearchEntry $entry): void
    {
        $this->client()->index($this->indexName((string) $entry->type))->addDocuments([[
            'id' => (string) $entry->id,
            'workspace_id' => (string) $entry->workspace_id,
            'type' => (string) $entry->type,
            'indexable_id' => (string) $entry->indexable_id,
            'content' => (string) $entry->content,
        ]], 'id');
    }

    public function remove(string $type, string $indexableId): void
    {
        // Deleted by filter rather than by key: the document is keyed by the
        // search-entry id, which the caller no longer has once the row it
        // described has gone.
        $this->client()
            ->index($this->indexName($type))
            ->deleteDocuments(['filter' => 'indexable_id = "'.$indexableId.'"']);
    }

    public function client(): Client
    {
        return $this->client ??= new Client(
            (string) config('search.meilisearch.host'),
            config('search.meilisearch.key'),
        );
    }

    public function isReachable(): bool
    {
        try {
            return $this->client()->isHealthy();
        } catch (\Throwable) {
            return false;
        }
    }

    private function indexName(string $type): string
    {
        return trim((string) config('search.meilisearch.prefix', 'finora'), '_').'_'.$type;
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function resolveTypes(array $types): array
    {
        $known = array_values(array_intersect(self::TYPES, $types));

        return $known === [] ? self::TYPES : $known;
    }
}
