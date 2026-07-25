<?php

declare(strict_types=1);

namespace Modules\Investment\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the investment module, carrying a machine-readable code and the
 * HTTP status the API should answer with.
 *
 * Same contract as LedgerException: the code stays English and stable, and the
 * client translates it (docs/05-api-conventions.md).
 */
final class InvestmentException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function investmentNotFound(string $id): self
    {
        return new self('investment_not_found', "Investment [{$id}] does not exist in this workspace.", 404);
    }

    public static function unknownAction(string $action): self
    {
        return new self('unknown_trade_action', "Unknown trade action [{$action}].");
    }

    public static function unknownKind(string $kind): self
    {
        return new self('unknown_investment_kind', "Unknown investment kind [{$kind}].");
    }

    public static function currencyMismatch(string $given, string $expected): self
    {
        return new self(
            'currency_mismatch',
            "Trade currency {$given} does not match the investment currency {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
        );
    }

    public static function nonPositiveQuantity(): self
    {
        return new self(
            'non_positive_quantity',
            'Quantity must be greater than zero; direction is expressed by the action.',
        );
    }

    public static function negativePrice(): self
    {
        return new self('negative_price', 'Price must not be negative.');
    }

    public static function negativeFee(): self
    {
        return new self('negative_fee', 'Fee must not be negative.');
    }

    /** Selling what you do not hold would invent a position out of nothing. */
    public static function insufficientQuantity(string $held, string $requested): self
    {
        return new self(
            'insufficient_quantity',
            "Cannot sell {$requested} units; only {$held} are held.",
            422,
            ['held' => $held, 'requested' => $requested],
        );
    }

    public static function invalidSplitRatio(string $ratio): self
    {
        return new self('invalid_split_ratio', "Split ratio [{$ratio}] must be greater than zero.");
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
