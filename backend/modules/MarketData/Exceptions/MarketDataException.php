<?php

declare(strict_types=1);

namespace Modules\MarketData\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the market-data module, carrying a machine-readable code and the
 * HTTP status the API should answer with (docs/05-api-conventions.md §4).
 */
final class MarketDataException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function providerUnavailable(string $provider, string $reason): self
    {
        return new self(
            'service_unavailable',
            "Market data provider [{$provider}] is unavailable: {$reason}.",
            503,
            ['provider' => $provider],
        );
    }

    public static function unknownSymbol(string $symbol): self
    {
        return new self('not_found', "No market data for [{$symbol}].", 404, ['symbol' => $symbol]);
    }

    public static function unknownCurrency(string $code): self
    {
        return new self('validation_failed', "Unknown currency [{$code}].", 422, ['currency' => $code]);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => request()->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
