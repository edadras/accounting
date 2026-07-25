<?php

declare(strict_types=1);

namespace Modules\Family\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the family module, carrying a machine-readable code and the HTTP
 * status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class FamilyException extends RuntimeException
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

    public static function memberNotFound(string $id): self
    {
        return new self('family_member_not_found', "Family member [{$id}] does not exist in this workspace.", 404);
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

    public static function allowanceAlreadyPaid(string $memberId, string $period): self
    {
        return new self(
            'allowance_already_paid',
            "The allowance for member [{$memberId}] in period [{$period}] has already been paid.",
            409,
            ['member_id' => $memberId, 'period' => $period],
        );
    }

    public static function noAllowanceConfigured(string $memberId): self
    {
        return new self(
            'no_allowance_configured',
            "Member [{$memberId}] has no monthly allowance, so there is nothing to pay.",
            422,
            ['member_id' => $memberId],
        );
    }

    public static function nonPositiveAllowance(): self
    {
        return new self('non_positive_allowance', 'An allowance must be greater than zero.');
    }

    public static function memberHasNoAccount(string $memberId): self
    {
        return new self(
            'member_has_no_account',
            "Member [{$memberId}] has no account, so money cannot move to or from them.",
            422,
            ['member_id' => $memberId],
        );
    }

    public static function payerIsRecipient(string $memberId): self
    {
        return new self(
            'payer_is_recipient',
            "Member [{$memberId}] cannot pay their own allowance.",
            422,
            ['member_id' => $memberId],
        );
    }

    public static function currencyMismatch(string $given, string $expected): self
    {
        return new self(
            'currency_mismatch',
            "Allowance currency {$given} does not match the account currency {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
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
