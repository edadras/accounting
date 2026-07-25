<?php

declare(strict_types=1);

namespace Modules\Search\Support;

use Modules\Documents\Models\Document;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Models\Transaction;
use Modules\Search\Contracts\SearchEngine;

/**
 * Turns matched ids into the records the API returns.
 *
 * Shared by every engine, which is what makes them interchangeable in
 * practice rather than only in the type system: whether a hit came from a LIKE
 * over `search_index`, from Meilisearch or from a cosine score, the client
 * receives the same shape.
 *
 * It also puts the last workspace check on the read path. The ids arrive from
 * an index — possibly one living in another process — and are resolved here
 * through scoped Eloquent queries, so an id that does not belong to the active
 * workspace hydrates to nothing at all.
 */
final class ResultHydrator
{
    /**
     * Records for $ids, in each type's own natural order.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function hydrate(string $type, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return match ($type) {
            SearchEngine::TYPE_TRANSACTIONS => $this->transactions($ids),
            SearchEngine::TYPE_DOCUMENTS => $this->documents($ids),
            SearchEngine::TYPE_CATEGORIES => $this->categories($ids),
            default => [],
        };
    }

    /**
     * The same records keyed by id, for a caller that has its own ordering —
     * a relevance score, say — and only needs the lookup.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function keyed(string $type, array $ids): array
    {
        $keyed = [];

        foreach ($this->hydrate($type, $ids) as $record) {
            $keyed[(string) $record['id']] = $record;
        }

        return $keyed;
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
}
