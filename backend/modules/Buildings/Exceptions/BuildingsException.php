<?php

declare(strict_types=1);

namespace Modules\Buildings\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the buildings module, carrying a machine-readable code and the
 * HTTP status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 *
 * Implementing Responsable is what lets the framework render this as the module
 * error envelope without the application kernel having to know the module
 * exists.
 */
final class BuildingsException extends RuntimeException implements Responsable
{
    /** @param  array<string, mixed>  $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function buildingNotFound(string $id): self
    {
        return new self('building_not_found', "Building [{$id}] does not exist in this workspace.", 404);
    }

    public static function unitNotInBuilding(string $unitId, string $buildingId): self
    {
        return new self(
            'unit_not_in_building',
            "Unit [{$unitId}] does not belong to building [{$buildingId}].",
            422,
            ['unit_id' => $unitId, 'building_id' => $buildingId],
        );
    }

    public static function unknownChargeFormula(string $formula): self
    {
        return new self('unknown_charge_formula', "Unknown charge formula [{$formula}].");
    }

    public static function invalidPeriod(string $period): self
    {
        return new self(
            'invalid_period',
            "Period [{$period}] is not a valid YYYY-MM month.",
            422,
            ['period' => $period],
        );
    }

    public static function buildingHasNoUnits(string $buildingId): self
    {
        return new self(
            'building_has_no_units',
            "Building [{$buildingId}] has no units to charge.",
            422,
            ['building_id' => $buildingId],
        );
    }

    public static function zeroWeightTotal(string $formula): self
    {
        return new self(
            'zero_weight_total',
            "Formula [{$formula}] gives every unit a weight of zero, so the charge cannot be split.",
            422,
            ['charge_formula' => $formula],
        );
    }

    public static function nonPositiveAmount(): self
    {
        return new self('non_positive_amount', 'Amount must be greater than zero.');
    }

    public static function currencyMismatch(string $given, string $expected): self
    {
        return new self(
            'currency_mismatch',
            "Currency {$given} does not match the expected currency {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
        );
    }

    public static function fundAccountMissing(string $buildingId): self
    {
        return new self(
            'fund_account_missing',
            "Building [{$buildingId}] has no fund account, so payments have nowhere to land.",
            422,
            ['building_id' => $buildingId],
        );
    }

    public static function chargeAlreadySettled(string $chargeId): self
    {
        return new self(
            'charge_already_settled',
            "Charge [{$chargeId}] is already paid in full.",
            422,
            ['charge_id' => $chargeId],
        );
    }

    public static function overpayment(string $chargeId, int $offered, int $remaining, string $currency): self
    {
        return new self(
            'overpayment_refused',
            "Payment of {$offered} exceeds the {$remaining} still owed on charge [{$chargeId}].",
            422,
            [
                'charge_id' => $chargeId,
                'offered' => $offered,
                'remaining' => $remaining,
                'currency' => $currency,
            ],
        );
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => $request?->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
