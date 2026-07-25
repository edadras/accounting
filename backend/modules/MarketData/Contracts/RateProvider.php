<?php

declare(strict_types=1);

namespace Modules\MarketData\Contracts;

/**
 * A source of "1 unit of base buys how many units of quote".
 *
 * Implementations transport and nothing else. They do not decide whether a
 * number is believable — RateGuard does, in one place, so a new feed cannot
 * arrive with its own idea of what counts as a plausible rate.
 */
interface RateProvider
{
    /** Stored verbatim in `exchange_rates.source`, so a row says where it came from. */
    public function name(): string;

    /**
     * @param  list<string>  $quotes
     * @return array<string, mixed> quote code => rate, exactly as the feed gave it
     *
     * @throws \Throwable when the feed cannot be reached
     */
    public function rates(string $base, array $quotes): array;
}
