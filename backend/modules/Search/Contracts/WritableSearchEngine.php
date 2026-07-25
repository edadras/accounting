<?php

declare(strict_types=1);

namespace Modules\Search\Contracts;

use Modules\Search\Models\SearchEntry;

/**
 * An engine that keeps its own copy of the index and therefore has to be told
 * when a record changes.
 *
 * DatabaseSearchEngine does not implement this, and that is the point: for it
 * the `search_index` table *is* the index, so writing the row is the whole of
 * indexing and a second notification would be a second copy to keep in step.
 * Meilisearch holds its own documents, so it needs one.
 *
 * SearchIndexer asks whether the bound engine implements this rather than
 * knowing which engines exist, so adding a third one touches no caller.
 */
interface WritableSearchEngine extends SearchEngine
{
    /** Creates the indexes and their settings. Safe to call repeatedly. */
    public function configure(): void;

    public function put(SearchEntry $entry): void;

    public function remove(string $type, string $indexableId): void;
}
