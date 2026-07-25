<?php

declare(strict_types=1);

namespace Modules\Business\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the business module, carrying a machine-readable code and the
 * HTTP status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class BusinessException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function contactNotFound(string $id): self
    {
        return new self('contact_not_found', "Contact [{$id}] does not exist in this workspace.", 404);
    }

    public static function projectNotFound(string $id): self
    {
        return new self('project_not_found', "Project [{$id}] does not exist in this workspace.", 404);
    }

    public static function invoiceNotFound(string $id): self
    {
        return new self('invoice_not_found', "Invoice [{$id}] does not exist in this workspace.", 404);
    }

    public static function unknownInvoiceDirection(string $direction): self
    {
        return new self('unknown_invoice_direction', "Unknown invoice direction [{$direction}].");
    }

    public static function invoiceHasNoItems(): self
    {
        return new self('invoice_has_no_items', 'An invoice needs at least one line item.');
    }

    public static function invalidQuantity(string $quantity): self
    {
        return new self('invalid_quantity', "Quantity [{$quantity}] must be greater than zero.");
    }

    public static function invalidDecimal(string $value, int $scale): self
    {
        return new self(
            'invalid_decimal',
            "[{$value}] is not a decimal with at most {$scale} places.",
            422,
            ['value' => $value, 'scale' => $scale],
        );
    }

    public static function negativeAmount(string $field): self
    {
        return new self('negative_amount', "[{$field}] must not be negative.", 422, ['field' => $field]);
    }

    public static function lineDiscountExceedsLine(int $index, int $discount, int $lineTotal): self
    {
        return new self(
            'line_discount_exceeds_line',
            "The discount on line {$index} is larger than the line itself.",
            422,
            ['line' => $index, 'discount' => $discount, 'line_total' => $lineTotal],
        );
    }

    public static function discountExceedsSubtotal(int $discount, int $available): self
    {
        return new self(
            'discount_exceeds_subtotal',
            'The invoice discount is larger than the amount there is to discount.',
            422,
            ['discount' => $discount, 'available' => $available],
        );
    }

    /**
     * The totals no longer add up. This is a bug, not user error: it means the
     * arithmetic drifted, so it fails loudly rather than persisting an invoice
     * that disagrees with itself.
     */
    public static function inconsistentTotals(int $subtotal, int $discount, int $tax, int $total): self
    {
        return new self(
            'inconsistent_invoice_totals',
            "Invoice totals do not reconcile: {$subtotal} - {$discount} + {$tax} != {$total}.",
            500,
            ['subtotal' => $subtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $total],
        );
    }

    public static function duplicateInvoiceNumber(string $number): self
    {
        return new self(
            'duplicate_invoice_number',
            "Invoice number [{$number}] is already used in this workspace.",
            409,
            ['number' => $number],
        );
    }

    public static function couldNotAllocateInvoiceNumber(string $scope): self
    {
        return new self(
            'could_not_allocate_invoice_number',
            "No free invoice number could be allocated for [{$scope}].",
            500,
            ['scope' => $scope],
        );
    }

    public static function invoiceIsVoid(string $number): self
    {
        return new self('invoice_is_void', "Invoice [{$number}] is void and cannot be paid.");
    }

    public static function invoiceHasPayments(string $number): self
    {
        return new self(
            'invoice_has_payments',
            "Invoice [{$number}] has payments against it; reverse them before voiding.",
        );
    }

    public static function invoiceAlreadySettled(string $number): self
    {
        return new self('invoice_already_settled', "Invoice [{$number}] is already paid in full.");
    }

    public static function nonPositivePayment(): self
    {
        return new self('non_positive_payment', 'A payment must be greater than zero.');
    }

    public static function overpayment(string $number, int $amount, int $outstanding): self
    {
        return new self(
            'payment_exceeds_balance',
            "Paying {$amount} against invoice [{$number}] exceeds the outstanding {$outstanding}.",
            422,
            ['amount' => $amount, 'outstanding' => $outstanding],
        );
    }

    public static function currencyMismatch(string $given, string $expected): self
    {
        return new self(
            'currency_mismatch',
            "Payment currency {$given} does not match the invoice currency {$expected}.",
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
