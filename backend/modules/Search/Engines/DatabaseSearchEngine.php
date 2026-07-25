<?php

declare(strict_types=1);

namespace Modules\Search\Engines;

use Modules\Documents\Models\Document;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Models\SearchEntry;
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
            $ids = $this->matchingIds($type, $pattern, $limit);

            if ($ids === []) {
                continue;
            }

            $results[$type] = match ($type) {
                self::TYPE_TRANSACTIONS => $this->transactions($ids),
                self::TYPE_DOCUMENTS => $this->documents($ids),
                self::TYPE_CATEGORIES => $this->categories($ids),
            };
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

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function transactions(array $ids): array
    {
        return Transaction::query()
            ->with('category:id,name,path')
            ->whereIn('id', $ids)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'description' => $transaction->description,
                'payee' => $transaction->payee,
                'notes' => $transaction->notes,
                'amount' => [
                    'value' => $transaction->amount,
                    'currency' => $transaction->currency,
                ],
                'occurred_at' => $transaction->occurred_at?->toIso8601String(),
                'category' => $transaction->category === null ? null : [
                    'id' => $transaction->category->id,
                    'name' => $transaction->category->name,
                    'path' => $transaction->category->path,
                ],
            ])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function documents(array $ids): array
    {
        return Document::query()
            ->whereIn('id', $ids)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'original_name' => $document->original_name,
                'kind' => $document->kind,
                'mime' => $document->mime,
                'size' => $document->size,
                'ocr_status' => $document->ocr_status,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function categories(array $ids): array
    {
        return Category::query()
            ->whereIn('id', $ids)
            ->orderBy('path')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'path' => $category->path,
                'type' => $category->type,
                'depth' => $category->depth,
            ])
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
