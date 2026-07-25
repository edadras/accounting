<?php

declare(strict_types=1);

namespace Modules\Search\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Search\Contracts\SearchEngine;
use Modules\Search\Support\TextNormalizer;

final class SearchController
{
    public function __construct(private readonly SearchEngine $engine) {}

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

        $groups = $this->engine->search($data['q'], $types, $limit);

        return response()->json([
            'data' => $groups,
            'meta' => [
                'query' => $data['q'],
                'normalized_query' => TextNormalizer::normalize($data['q']),
                'types' => array_keys($groups),
                'limit' => $limit,
                'total' => array_sum(array_map('count', $groups)),
            ],
        ]);
    }
}
