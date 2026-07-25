<?php

declare(strict_types=1);

namespace Modules\Search\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ExtractSearchFilter;
use Modules\AI\Contracts\EmbeddingProvider;
use Modules\AI\Models\Embedding;
use Modules\AI\Support\Vector;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Models\SearchEntry;
use Modules\Search\Support\ResultHydrator;
use Modules\Search\Support\TextNormalizer;

/**
 * The pipeline of docs/08-ai-layer.md §4, in the order the document gives it:
 *
 *   1. The model turns the sentence into a structured filter — type, period.
 *   2. The filter narrows the corpus, exactly and cheaply.
 *   3. What is left is re-ranked by semantic similarity.
 *   4. A numeric summary comes back alongside the rows.
 *
 * The reason this exists rather than a second keyword index is the example the
 * document itself uses: «تمام هزینه‌های مربوط به ماشین» has to find petrol,
 * the garage, the insurance, the fine and the tyres even though the user filed
 * them under five different categories and none of those words is «ماشین».
 * Keyword search cannot do that at any level of cleverness — the words simply
 * are not there. Similarity can, because the vectors were built by something
 * that knows what those words are about.
 *
 * A literal hit still counts. Someone who types a merchant name wants that
 * merchant first, not whatever is most thematically adjacent to it, so the
 * final score blends the two and either alone is enough to qualify a row.
 */
final readonly class SemanticSearch
{
    public function __construct(
        private WorkspaceContext $context,
        private EmbeddingProvider $embeddings,
        private ExtractSearchFilter $filters,
        private ResultHydrator $hydrator,
    ) {}

    /**
     * @param  list<string>  $types
     * @return array{
     *   results: list<array<string, mixed>>,
     *   filter: array<string, mixed>,
     *   summary: array<string, mixed>,
     *   normalized_query: string,
     *   model: string,
     *   scanned: int,
     * }
     */
    public function handle(string $query, array $types = [], int $limit = SearchEngine::DEFAULT_LIMIT): array
    {
        $this->context->require();

        $types = $this->resolveTypes($types);
        $limit = max(1, $limit);
        $normalized = TextNormalizer::normalize($query);
        $filter = $this->filters->handle($query);

        $empty = [
            'results' => [],
            'filter' => $filter,
            'summary' => ['transaction_count' => 0, 'total' => 0, 'currency' => $this->context->baseCurrency()],
            'normalized_query' => $normalized,
            'model' => $this->embeddings->name(),
            'scanned' => 0,
        ];

        if ($normalized === '') {
            return $empty;
        }

        $entries = SearchEntry::query()
            ->whereIn('type', $types)
            ->limit(max(1, (int) config('search.semantic.max_candidates', 2000)))
            ->get(['id', 'type', 'indexable_type', 'indexable_id', 'content']);

        if ($entries->isEmpty()) {
            return $empty;
        }

        $allowedTransactions = $this->transactionsMatching($filter);
        $vectors = $this->vectorsFor(
            array_values($entries->pluck('indexable_id')->map(strval(...))->all())
        );
        $needle = $this->embeddings->embed($normalized);

        $minimum = (float) config('search.semantic.min_similarity', 0.12);
        $lexicalWeight = (float) config('search.semantic.lexical_weight', 0.35);
        $terms = $this->terms($normalized);

        $scored = [];

        foreach ($entries as $entry) {
            $id = (string) $entry->indexable_id;

            // The structured filter only speaks about transactions: a receipt
            // or a category has no type and no date to test, and dropping them
            // would lose the «رسیدهای مرتبط» half of the documented answer.
            if ($entry->type === SearchEngine::TYPE_TRANSACTIONS
                && $allowedTransactions !== null
                && ! isset($allowedTransactions[$id])) {
                continue;
            }

            $semantic = Vector::cosine($needle, $vectors[$entry->indexable_type.'|'.$id] ?? []);
            $lexical = $this->lexicalScore((string) $entry->content, $terms);

            if ($semantic < $minimum && $lexical <= 0.0) {
                continue;
            }

            $scored[] = [
                'type' => $entry->type,
                'id' => $id,
                'score' => round((1 - $lexicalWeight) * max(0.0, $semantic) + $lexicalWeight * $lexical, 6),
                'semantic_score' => round($semantic, 6),
                'lexical_score' => round($lexical, 6),
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['id'], $b['id']));

        $scored = array_slice($scored, 0, $limit);

        return [
            'results' => $this->attachRecords($scored),
            'filter' => $filter,
            'summary' => $this->summarize($scored),
            'normalized_query' => $normalized,
            'model' => $this->embeddings->name(),
            'scanned' => $entries->count(),
        ];
    }

    /**
     * The ids the structured filter admits, or null when it constrains nothing.
     *
     * Resolved through the scoped model rather than applied to the index, so
     * the date and type tests run against the ledger's own columns instead of
     * against a flattened copy of them.
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, true>|null
     */
    private function transactionsMatching(array $filter): ?array
    {
        $type = $filter['type'] ?? null;
        $from = $filter['from'] ?? null;
        $to = $filter['to'] ?? null;

        if ($type === null && $from === null && $to === null) {
            return null;
        }

        $query = Transaction::query();

        if (is_string($type) && $type !== '') {
            $query->ofType($type);
        }

        if (is_string($from) && $from !== '') {
            $query->where('occurred_at', '>=', CarbonImmutable::parse($from)->startOfDay());
        }

        if (is_string($to) && $to !== '') {
            $query->where('occurred_at', '<=', CarbonImmutable::parse($to)->endOfDay());
        }

        return array_fill_keys($query->pluck('id')->all(), true);
    }

    /**
     * @param  list<string>  $ownerIds
     * @return array<string, list<float>>
     */
    private function vectorsFor(array $ownerIds): array
    {
        $vectors = [];

        Embedding::query()
            ->forModel($this->embeddings->name())
            ->whereIn('owner_id', $ownerIds)
            ->select(['owner_type', 'owner_id', 'vector'])
            ->chunk(500, function ($rows) use (&$vectors): void {
                foreach ($rows as $row) {
                    $vectors[$row->owner_type.'|'.$row->owner_id] = $row->vector();
                }
            });

        return $vectors;
    }

    /**
     * How much of the query appears verbatim in the indexed text.
     *
     * @param  list<string>  $terms
     */
    private function lexicalScore(string $content, array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }

        $hits = 0;

        foreach ($terms as $term) {
            if (str_contains($content, $term)) {
                $hits++;
            }
        }

        return $hits / count($terms);
    }

    /** @return list<string> */
    private function terms(string $normalized): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, static fn (string $term): bool => mb_strlen($term) > 1));
    }

    /**
     * @param  list<array<string, mixed>>  $scored
     * @return list<array<string, mixed>>
     */
    private function attachRecords(array $scored): array
    {
        $byType = [];

        foreach ($scored as $hit) {
            $byType[$hit['type']][] = (string) $hit['id'];
        }

        $records = [];

        foreach ($byType as $type => $ids) {
            $records[$type] = $this->hydrator->keyed((string) $type, $ids);
        }

        $results = [];

        foreach ($scored as $hit) {
            $record = $records[$hit['type']][$hit['id']] ?? null;

            // A hit whose record does not hydrate belonged to another
            // workspace or has since been deleted. Either way it is not a
            // result.
            if ($record === null) {
                continue;
            }

            $results[] = $hit + ['record' => $record];
        }

        return $results;
    }

    /**
     * The «خلاصهٔ عددی» the document asks for: what the matched spending adds
     * up to, in the base currency the amounts were frozen against.
     *
     * @param  list<array<string, mixed>>  $scored
     * @return array<string, mixed>
     */
    private function summarize(array $scored): array
    {
        $base = $this->context->baseCurrency();

        $ids = array_values(array_map(
            static fn (array $hit): string => (string) $hit['id'],
            array_filter($scored, static fn (array $hit): bool => $hit['type'] === SearchEngine::TYPE_TRANSACTIONS),
        ));

        if ($ids === []) {
            return ['transaction_count' => 0, 'total' => 0, 'currency' => $base];
        }

        $rows = Transaction::query()
            ->whereIn('id', $ids)
            ->where('base_currency', $base)
            ->get(['id', 'base_amount', 'type']);

        return [
            'transaction_count' => count($ids),
            'total' => (int) $rows->where('type', Transaction::TYPE_EXPENSE)->sum('base_amount'),
            'currency' => $base,
        ];
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function resolveTypes(array $types): array
    {
        $known = array_values(array_intersect(SearchEngine::TYPES, $types));

        return $known === [] ? SearchEngine::TYPES : $known;
    }
}
