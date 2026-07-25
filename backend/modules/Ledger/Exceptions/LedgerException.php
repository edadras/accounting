<?php

declare(strict_types=1);

namespace Modules\Ledger\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the ledger, carrying a machine-readable code and the HTTP status
 * the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class LedgerException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unknownTransactionType(string $type): self
    {
        return new self('unknown_transaction_type', "Unknown transaction type [{$type}].");
    }

    public static function accountNotFound(string $id): self
    {
        return new self('account_not_found', "Account [{$id}] does not exist in this workspace.", 404);
    }

    public static function categoryNotFound(string $id): self
    {
        return new self('category_not_found', "Category [{$id}] does not exist in this workspace.", 404);
    }

    public static function currencyMismatch(string $given, string $expected): self
    {
        return new self(
            'currency_mismatch',
            "Transaction currency {$given} does not match the account currency {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
        );
    }

    public static function negativeAmount(): self
    {
        return new self(
            'negative_amount',
            'Amount must be positive; direction is expressed by the transaction type.',
        );
    }

    public static function zeroAmount(): self
    {
        return new self('zero_amount', 'Amount must be greater than zero.');
    }

    public static function transferNeedsCounterAccount(): self
    {
        return new self('transfer_needs_counter_account', 'A transfer requires a destination account.');
    }

    public static function transferToSameAccount(): self
    {
        return new self('transfer_to_same_account', 'Source and destination accounts must differ.');
    }

    public static function unbalanced(string $transactionId, int $difference): self
    {
        return new self(
            'unbalanced_transaction',
            "Transaction [{$transactionId}] does not balance; debits and credits differ by {$difference}.",
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
}
