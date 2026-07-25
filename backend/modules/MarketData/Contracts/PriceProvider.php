<?php

declare(strict_types=1);

namespace Modules\MarketData\Contracts;

use Modules\MarketData\Support\PriceQuote;

/**
 * A source of per-unit prices for the symbols the workspaces actually hold.
 */
interface PriceProvider
{
    /** Stored verbatim in `price_snapshots.source`. */
    public function name(): string;

    /**
     * @param  list<string>  $symbols
     * @return array<string, PriceQuote> keyed by symbol
     *
     * @throws \Throwable when the feed cannot be reached
     */
    public function prices(array $symbols): array;
}
