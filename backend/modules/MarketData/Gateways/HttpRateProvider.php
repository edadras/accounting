<?php

declare(strict_types=1);

namespace Modules\MarketData\Gateways;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\MarketData\Contracts\RateProvider;
use Modules\MarketData\Exceptions\MarketDataException;

/**
 * The configured rate feed, spoken over plain HTTP.
 *
 * No SDK and no vendor lock: one GET against `market.rates.endpoint`, and three
 * common response shapes are accepted so a proxy, a self-hosted mirror or a
 * different vendor is a config change.
 *
 * Values are handed on exactly as received. Deciding whether one of them is a
 * believable rate is RateGuard's job, not a transport's.
 */
final class HttpRateProvider implements RateProvider
{
    public function name(): string
    {
        return (string) config('market.rates.source', 'remote');
    }

    public function rates(string $base, array $quotes): array
    {
        $endpoint = (string) config('market.rates.endpoint');

        if ($endpoint === '') {
            throw MarketDataException::providerUnavailable($this->name(), 'no endpoint configured');
        }

        $response = $this->client()->get($endpoint, [
            'base' => $base,
            'symbols' => implode(',', $quotes),
        ]);

        if ($response->failed()) {
            throw MarketDataException::providerUnavailable($this->name(), 'HTTP '.$response->status());
        }

        return $this->extract($response->json(), $quotes);
    }

    /**
     * @param  list<string>  $quotes
     * @return array<string, mixed>
     */
    private function extract(mixed $body, array $quotes): array
    {
        foreach ([data_get($body, 'rates'), data_get($body, 'data.rates'), $body] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $found = [];

            foreach ($quotes as $quote) {
                $code = strtoupper($quote);

                if (array_key_exists($code, $candidate)) {
                    $found[$code] = $candidate[$code];
                }
            }

            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    private function client(): PendingRequest
    {
        $key = (string) config('market.rates.key');

        $client = Http::acceptJson()
            ->timeout((int) config('market.timeout', 8))
            ->retry(max(1, (int) config('market.retries', 1)), 200, throw: false);

        return $key === '' ? $client : $client->withToken($key);
    }
}
