<?php

declare(strict_types=1);

namespace Modules\MarketData\Support;

/**
 * One price as a feed reported it.
 *
 * `price` stays a string all the way to the database: a JSON float has already
 * lost whatever it was going to lose by the time PHP sees it, and there is no
 * reason for this module to lose any more.
 */
final readonly class PriceQuote
{
    public function __construct(
        public string $symbol,
        public string $price,
        public string $currency,

        /** Null when the feed does not say; the holding's own kind is used then. */
        public ?string $kind = null,
    ) {}
}
