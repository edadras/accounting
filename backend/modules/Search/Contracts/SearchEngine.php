<?php

declare(strict_types=1);

namespace Modules\Search\Contracts;

/**
 * One question — "where does `گوشت` appear?" — asked of whatever backend is
 * wired up.
 *
 * The database implementation is the one that exists today; Meilisearch lands
 * in a later milestone and replaces the binding, not the callers.
 */
interface SearchEngine
{
    public const TYPE_TRANSACTIONS = 'transactions';

    public const TYPE_DOCUMENTS = 'documents';

    public const TYPE_CATEGORIES = 'categories';

    public const TYPES = [
        self::TYPE_TRANSACTIONS,
        self::TYPE_DOCUMENTS,
        self::TYPE_CATEGORIES,
    ];

    public const DEFAULT_LIMIT = 20;

    /**
     * Results grouped by type, always workspace-scoped.
     *
     * An empty $types means every type. $limit applies per type.
     *
     * @param  list<string>  $types
     * @return array<string, list<array<string, mixed>>>
     */
    public function search(string $query, array $types = [], int $limit = self::DEFAULT_LIMIT): array;
}
