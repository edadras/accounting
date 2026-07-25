<?php

declare(strict_types=1);

namespace Modules\Travel\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refusal by the travel module, carrying a machine-readable code and the HTTP
 * status the API should answer with.
 *
 * Mirrors LedgerException: the code stays English and stable, and the message
 * the user sees is translated client-side from that code
 * (docs/05-api-conventions.md).
 */
final class TravelException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function tripNotFound(string $id): self
    {
        return new self('trip_not_found', "Trip [{$id}] does not exist in this workspace.", 404);
    }

    public static function memberNotInTrip(string $id): self
    {
        return new self('member_not_in_trip', "Member [{$id}] is not part of this trip.", 404);
    }

    public static function unknownSplitMode(string $mode): self
    {
        return new self('unknown_split_mode', "Unknown split mode [{$mode}].");
    }

    public static function noParticipants(): self
    {
        return new self('no_participants', 'An expense must be split across at least one member.');
    }

    public static function duplicateParticipant(string $id): self
    {
        return new self('duplicate_participant', "Member [{$id}] appears twice in the split.");
    }

    public static function negativeAmount(): self
    {
        return new self('negative_amount', 'A split expense amount must be positive.');
    }

    public static function zeroAmount(): self
    {
        return new self('zero_amount', 'A split expense amount must be greater than zero.');
    }

    public static function exactSharesDoNotSum(int $given, int $expected): self
    {
        return new self(
            'exact_shares_do_not_sum',
            "Exact shares add up to {$given} but the expense is {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
        );
    }

    public static function percentagesDoNotSum(string $given): self
    {
        return new self(
            'percentages_do_not_sum',
            "Split percentages add up to {$given}%, not 100%.",
            422,
            ['given' => $given],
        );
    }

    public static function nonPositiveWeights(): self
    {
        return new self('non_positive_weights', 'Split weights must sum to a positive number.');
    }

    public static function negativeShare(string $memberId): self
    {
        return new self('negative_share', "The share for member [{$memberId}] cannot be negative.");
    }

    public static function currencyMismatch(string $given, string $expected): self
    {
        return new self(
            'currency_mismatch',
            "Settlement currency {$given} does not match the trip base currency {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
        );
    }

    public static function unbalancedTrip(string $tripId, int $difference): self
    {
        return new self(
            'unbalanced_trip',
            "Trip [{$tripId}] does not balance; member balances differ from zero by {$difference}.",
            500,
            ['difference' => $difference],
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

    /**
     * Laravel's handler calls this when the exception reaches it, which lets
     * the module answer with its own error shape without the application
     * bootstrap having to know the module exists.
     */
    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson() ? $this->toResponse() : null;
    }
}
