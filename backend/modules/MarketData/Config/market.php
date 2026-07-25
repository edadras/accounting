<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Rate feed
    |--------------------------------------------------------------------------
    |
    | With no endpoint the container binds StaticProvider, which carries the
    | same seed table ExchangeRateResolver falls back to. That is the supported
    | no-network configuration, not a mock: out of the box the ledger answers
    | exactly the numbers it answered before this module existed.
    |
    | The endpoint is expected to answer `{"rates": {"EUR": "0.92", ...}}`;
    | `data.rates` and a flat map are accepted too, so pointing this at a proxy
    | or a different vendor is a config change rather than a code change.
    |
    */

    'rates' => [
        'endpoint' => env('MARKET_RATES_ENDPOINT'),
        'key' => env('MARKET_RATES_KEY'),
        'source' => env('MARKET_RATES_SOURCE', 'remote'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Price feed
    |--------------------------------------------------------------------------
    |
    | Answers `{"prices": [{"symbol": "BTC", "price": "64000.10",
    | "currency": "USD", "kind": "crypto"}]}`, or a flat symbol => price map in
    | which case `currency` below says what the numbers are quoted in.
    |
    */

    'prices' => [
        'endpoint' => env('MARKET_PRICES_ENDPOINT'),
        'key' => env('MARKET_PRICES_KEY'),
        'source' => env('MARKET_PRICES_SOURCE', 'remote'),
        'currency' => env('MARKET_PRICES_CURRENCY', 'USD'),
    ],

    'timeout' => (int) env('MARKET_TIMEOUT', 8),

    // One attempt by default. A rate that is late is not a problem — the last
    // stored one stays authoritative — so there is nothing here worth making a
    // scheduled job sit and wait for.
    'retries' => (int) env('MARKET_RETRIES', 1),

    /*
    |--------------------------------------------------------------------------
    | What to fetch
    |--------------------------------------------------------------------------
    */

    'base' => env('MARKET_BASE', 'USD'),

    'quotes' => array_values(array_filter(array_map(
        static fn (string $code): string => strtoupper(trim($code)),
        explode(',', (string) env('MARKET_QUOTES', 'EUR,TRY,AED,IRR,IRT,USDT,BTC,ETH,XAU')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanity
    |--------------------------------------------------------------------------
    |
    | A rate more than this factor away from the last known one is refused and
    | recorded instead of stored. A wrong rate does not corrupt one number: it
    | re-values every multi-currency balance, every report and every open
    | position at once, and it does so silently. Refusing a real but violent
    | move costs one stale hour; accepting a decimal-point error costs trust in
    | the whole product.
    |
    */

    'max_change_factor' => (float) env('MARKET_MAX_CHANGE_FACTOR', 10),

    'history_limit' => (int) env('MARKET_HISTORY_LIMIT', 30),

    // docs/02-modules.md §4: price refreshes belong on the maintenance queue,
    // never in front of a user's request.
    'queue' => env('MARKET_QUEUE', 'maintenance'),

];
