<?php

declare(strict_types=1);

namespace Modules\MarketData\Gateways;

use App\Core\Money\Currency;
use Modules\Ledger\Actions\ExchangeRateResolver;
use Modules\MarketData\Contracts\PriceProvider;
use Modules\MarketData\Contracts\RateProvider;
use Modules\MarketData\Support\PriceQuote;

/**
 * The feed used when no endpoint is configured.
 *
 * It reads ExchangeRateResolver's own seed table and does the same arithmetic
 * the resolver does, so the numbers it produces are the numbers the ledger was
 * already using. That is the point: installing this module without configuring
 * a provider must not move a single balance.
 *
 * It is not a mock. It is the supported no-network configuration, the same way
 * the AI module's deterministic provider is (docs/07-security.md §5.6).
 */
final class StaticProvider implements PriceProvider, RateProvider
{
    public function name(): string
    {
        return 'static';
    }

    public function rates(string $base, array $quotes): array
    {
        $seed = ExchangeRateResolver::FALLBACK_TO_USD;
        $baseUsd = $seed[strtoupper(trim($base))] ?? null;

        if ($baseUsd === null) {
            return [];
        }

        $rates = [];

        foreach ($quotes as $quote) {
            $code = strtoupper(trim((string) $quote));
            $quoteUsd = $seed[$code] ?? null;

            if ($quoteUsd === null || $quoteUsd == 0.0) {
                continue;
            }

            // Same expression as ExchangeRateResolver::lookup(), so the stored
            // rate and the fallback it replaces agree digit for digit.
            $rates[$code] = (string) ($baseUsd / $quoteUsd);
        }

        return $rates;
    }

    public function prices(array $symbols): array
    {
        $seed = ExchangeRateResolver::FALLBACK_TO_USD;
        $quotes = [];

        foreach ($symbols as $symbol) {
            $code = strtoupper(trim((string) $symbol));

            // The seed table only knows things that are also currencies —
            // metals, crypto, cash. A listed share has no static price and is
            // left alone rather than given an invented one.
            if (! isset($seed[$code])) {
                continue;
            }

            $quotes[$code] = new PriceQuote($code, (string) $seed[$code], Currency::of('USD')->code);
        }

        return $quotes;
    }
}
