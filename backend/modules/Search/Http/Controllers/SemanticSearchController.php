<?php

declare(strict_types=1);

namespace Modules\Search\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Search\Actions\SemanticSearch;
use Modules\Search\Contracts\SearchEngine;

/**
 * `GET /api/v1/search/semantic?q=` — one ranked list rather than the grouped
 * shape of `/search`.
 *
 * Grouping by type is right for keyword search, where each group is its own
 * answer. It is wrong here: the whole output of the pipeline is a single
 * ordering by relevance, and splitting it into three would throw that away.
 */
final class SemanticSearchController
{
    public function __construct(private readonly SemanticSearch $search) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'types' => ['sometimes', 'string', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $types = array_values(array_filter(
            array_map(trim(...), explode(',', (string) ($data['types'] ?? ''))),
        ));

        $limit = (int) ($data['limit'] ?? SearchEngine::DEFAULT_LIMIT);

        $outcome = $this->search->handle($data['q'], $types, $limit);

        return response()->json([
            'data' => $outcome['results'],
            'meta' => [
                'query' => $data['q'],
                'normalized_query' => $outcome['normalized_query'],

                // What the model made of the question, returned so the client
                // can show it — «۴۷ تراکنش، هزینه، ۱ تا ۳۱ تیر» — and so a
                // wrong reading is visible rather than mysterious.
                'filter' => $outcome['filter'],
                'summary' => $outcome['summary'],
                'embedding_model' => $outcome['model'],
                'limit' => $limit,
                'scanned' => $outcome['scanned'],
                'total' => count($outcome['results']),
            ],
        ]);
    }
}
