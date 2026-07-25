<?php

declare(strict_types=1);

namespace Modules\Search\Engines;

use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Models\SearchEntry;
use Modules\Search\Support\ResultHydrator;
use Modules\Search\Support\TextNormalizer;

/**
 * Search without a search server: normalise the needle the same way the index
 * was normalised, LIKE one against the other, then hydrate the records.
 *
 * Every query runs through Eloquent, so the workspace global scope applies to
 * the index and to the records alike — a result from another workspace is not
 * merely unlikely but unreachable.
 */
final class DatabaseSearchEngine implements SearchEngine
{
    /**
     * `#` escapes LIKE's own wildcards. Without it a user typing `%` would
     * match their whole ledger.
     */
    private const LIKE_ESCAPE = '#';

    public function __construct(private readonly ResultHydrator $hydrator) {}

    public function search(string $query, array $types = [], int $limit = self::DEFAULT_LIMIT): array
    {
        $types = $this->resolveTypes($types);
        $limit = max(1, $limit);

        $results = array_fill_keys($types, []);
        $needle = TextNormalizer::normalize($query);

        if ($needle === '') {
            return $results;
        }

        $pattern = '%'.$this->escapeLike($needle).'%';

        foreach ($types as $type) {
            $results[$type] = $this->hydrator->hydrate($type, $this->matchingIds($type, $pattern, $limit));
        }

        return $results;
    }

    /** @return list<string> */
    private function matchingIds(string $type, string $pattern, int $limit): array
    {
        return SearchEntry::query()
            ->where('type', $type)
            ->whereRaw("content LIKE ? ESCAPE '".self::LIKE_ESCAPE."'", [$pattern])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('indexable_id')
            ->all();
    }

    private function escapeLike(string $needle): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $needle,
        );
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
