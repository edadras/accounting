<?php

declare(strict_types=1);

namespace Modules\Banking\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refusal by the banking module, carrying a machine-readable code and the
 * HTTP status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class BankingException extends RuntimeException
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

    public static function checkNotFound(string $id): self
    {
        return new self('check_not_found', "Cheque [{$id}] does not exist in this workspace.", 404);
    }

    public static function loanNotFound(string $id): self
    {
        return new self('loan_not_found', "Loan [{$id}] does not exist in this workspace.", 404);
    }

    public static function installmentNotFound(string $loanId, int $number): self
    {
        return new self(
            'installment_not_found',
            "Loan [{$loanId}] has no instalment number {$number}.",
            404,
        );
    }

    public static function checkNotClearable(string $status): self
    {
        return new self(
            'check_not_clearable',
            "A cheque in status [{$status}] cannot be cleared.",
            422,
            ['status' => $status],
        );
    }

    public static function unknownCheckDirection(string $direction): self
    {
        return new self('unknown_check_direction', "Unknown cheque direction [{$direction}].");
    }

    public static function unknownInterestType(string $type): self
    {
        return new self('unknown_interest_type', "Unknown interest type [{$type}].");
    }

    public static function invalidInstallmentsCount(int $count): self
    {
        return new self(
            'invalid_installments_count',
            "A loan needs at least one instalment, got {$count}.",
            422,
            ['installments_count' => $count],
        );
    }

    public static function nonPositivePrincipal(): self
    {
        return new self('non_positive_principal', 'Loan principal must be greater than zero.');
    }

    public static function nonPositivePayment(): self
    {
        return new self('non_positive_payment', 'A payment must be greater than zero.');
    }

    public static function overpayment(int $offered, int $remaining): self
    {
        return new self(
            'installment_overpayment',
            "Payment of {$offered} exceeds the {$remaining} still owed on this instalment.",
            422,
            ['offered' => $offered, 'remaining' => $remaining],
        );
    }

    public static function installmentAlreadyPaid(int $number): self
    {
        return new self(
            'installment_already_paid',
            "Instalment {$number} is already settled.",
            422,
            ['number' => $number],
        );
    }

    public static function currencyMismatch(string $given, string $expected): self
    {
        return new self(
            'currency_mismatch',
            "Currency {$given} does not match the account currency {$expected}.",
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

    /**
     * Laravel's handler calls this when the exception reaches it, which lets the
     * module answer with its own error shape without the application's
     * bootstrap having to know the module exists.
     */
    public function render(Request $request): ?JsonResponse
    {
        return $request->expectsJson() ? $this->toResponse() : null;
    }
}
