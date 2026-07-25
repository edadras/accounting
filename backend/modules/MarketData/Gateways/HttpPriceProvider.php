<?php

declare(strict_types=1);

namespace Modules\MarketData\Gateways;

use App\Core\Money\Currency;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\MarketData\Contracts\PriceProvider;
use Modules\MarketData\Exceptions\MarketDataException;
use Modules\MarketData\Support\PriceQuote;

/**
 * The configured price feed. Accepts either a list of quote objects or a flat
 * symbol => price map, in which case `market.prices.currency` says what the
 * numbers are quoted in.
 */
final class HttpPriceProvider implements PriceProvider
{
    public function name(): string
    {
        return (string) config('market.prices.source', 'remote');
    }

    public function prices(array $symbols): array
    {
        $endpoint = (string) config('market.prices.endpoint');

        if ($endpoint === '') {
            throw MarketDataException::providerUnavailable($this->name(), 'no endpoint configured');
        }

        $response = $this->client()->get($endpoint, [
            'symbols' => implode(',', $symbols),
        ]);

        if ($response->failed()) {
            throw MarketDataException::providerUnavailable($this->name(), 'HTTP '.$response->status());
        }

        return $this->extract($response->json(), $symbols);
    }

    /**
     * @param  list<string>  $symbols
     * @return array<string, PriceQuote>
     */
    private function extract(mixed $body, array $symbols): array
    {
        $rows = data_get($body, 'prices') ?? data_get($body, 'data') ?? $body;

        if (! is_array($rows)) {
            return [];
        }

        $wanted = array_map(strtoupper(...), $symbols);
        $fallback = $this->defaultCurrency();
        $quotes = [];

        foreach ($rows as $key => $row) {
            [$symbol, $price, $currency, $kind] = is_array($row)
                ? [
                    strtoupper((string) ($row['symbol'] ?? (is_string($key) ? $key : ''))),
                    $row['price'] ?? null,
                    strtoupper((string) ($row['currency'] ?? $fallback)),
                    isset($row['kind']) ? (string) $row['kind'] : null,
                ]
                : [is_string($key) ? strtoupper($key) : '', $row, $fallback, null];

            if ($symbol === '' || ! in_array($symbol, $wanted, true)) {
                continue;
            }

            $quotes[$symbol] = new PriceQuote(
                symbol: $symbol,
                price: is_scalar($price) && ! is_bool($price) ? (string) $price : '',
                currency: Currency::isSupported($currency) ? $currency : $fallback,
                kind: $kind,
            );
        }

        return $quotes;
    }

    private function defaultCurrency(): string
    {
        $code = strtoupper((string) config('market.prices.currency', 'USD'));

        return Currency::isSupported($code) ? $code : 'USD';
    }

    private function client(): PendingRequest
    {
        $key = (string) config('market.prices.key');

        $client = Http::acceptJson()
            ->timeout((int) config('market.timeout', 8))
            ->retry(max(1, (int) config('market.retries', 1)), 200, throw: false);

        return $key === '' ? $client : $client->withToken($key);
    }
}
