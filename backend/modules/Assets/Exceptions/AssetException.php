<?php

declare(strict_types=1);

namespace Modules\Assets\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the assets module, carrying a machine-readable code and the HTTP
 * status the API should answer with.
 *
 * Same contract as LedgerException (docs/05-api-conventions.md).
 */
final class AssetException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function assetNotFound(string $id): self
    {
        return new self('asset_not_found', "Asset [{$id}] does not exist in this workspace.", 404);
    }

    public static function unknownDepreciationMethod(string $method): self
    {
        return new self('unknown_depreciation_method', "Unknown depreciation method [{$method}].");
    }

    public static function usefulLifeRequired(string $method): self
    {
        return new self(
            'useful_life_required',
            "The {$method} depreciation method needs a useful life in whole years.",
        );
    }

    public static function depreciationRateRequired(): self
    {
        return new self(
            'depreciation_rate_required',
            'Declining-balance depreciation needs a rate greater than zero and below one.',
        );
    }

    public static function salvageAbovePurchase(int $salvage, int $purchase): self
    {
        return new self(
            'salvage_above_purchase',
            'Salvage value must not exceed the purchase price; there would be nothing to depreciate.',
            422,
            ['salvage_value' => $salvage, 'purchase_price' => $purchase],
        );
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
