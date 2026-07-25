<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    |
    | `database` is the default and always works: the normalised index is a
    | table, so a fresh checkout, a test run and a small self-hosted install
    | need nothing installed and nothing running.
    |
    | `meilisearch` is what docs/01 names for production — typo tolerance,
    | structured filters and speed that LIKE cannot reach. Switching is this
    | key; every caller goes through the SearchEngine contract either way.
    |
    */

    'driver' => env('SEARCH_DRIVER', 'database'),

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://127.0.0.1:7700'),

        'key' => env('MEILISEARCH_KEY'),

        // Indexes are named "<prefix>_transactions" and so on. The prefix
        // keeps several environments apart on one server, which is the usual
        // shape of a staging box.
        'prefix' => env('MEILISEARCH_PREFIX', 'finora'),

        'timeout' => (int) env('MEILISEARCH_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic search
    |--------------------------------------------------------------------------
    |
    | docs/08-ai-layer.md §4: a structured filter narrows the corpus, cosine
    | similarity over the embeddings ranks what is left, and a lexical hit
    | still counts for something because an exact word match is evidence too.
    |
    | `min_similarity` is the floor below which a row is not really about the
    | query and is dropped rather than shown at the bottom. `max_candidates`
    | bounds the similarity pass: it runs in PHP over one workspace's rows, and
    | a workspace with a hundred thousand transactions should get a slower
    | answer, not an out-of-memory one.
    |
    */

    'semantic' => [
        'min_similarity' => (float) env('SEARCH_SEMANTIC_MIN_SIMILARITY', 0.12),

        'max_candidates' => (int) env('SEARCH_SEMANTIC_MAX_CANDIDATES', 2000),

        'lexical_weight' => (float) env('SEARCH_SEMANTIC_LEXICAL_WEIGHT', 0.35),
    ],

];
