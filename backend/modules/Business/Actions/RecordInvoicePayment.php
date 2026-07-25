<?php

declare(strict_types=1);

namespace Modules\Business\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\Business\Exceptions\BusinessException;
use Modules\Business\Models\Invoice;
use Modules\Business\Models\Payment;
use Modules\Business\Models\Project;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;

/**
 * Settles part or all of an invoice and posts the money movement to the ledger.
 *
 * The ledger is the single source of truth for balances, so a payment is never
 * just a row in `payments`: it always produces exactly one transaction, income
 * for a sale and expense for a purchase. The invoice status is then re-derived
 * from the payments that exist, never incremented.
 *
 * The whole thing runs inside one transaction with the invoice locked, because
 * "is there anything left to pay?" and "record this payment" have to be one
 * indivisible step — otherwise two concurrent payments can each see the same
 * outstanding balance and together overpay it.
 *
 * @phpstan-type PaymentPayload array{
 *   id?: string,
 *   account_id: string,
 *   amount: int,
 *   currency?: string|null,
 *   paid_at?: DateTimeInterface|string|null,
 *   method?: string|null,
 *   category_id?: string|null,
 *   reference?: string|null,
 * }
 */
final readonly class RecordInvoicePayment
{
    public function __construct(private RecordTransaction $record) {}

    /**
     * @param  PaymentPayload  $data
     */
    public function handle(Invoice|string $invoice, array $data): Payment
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->id : $invoice;

        return DB::transaction(function () use ($invoiceId, $data): Payment {
            $paymentId = $data['id'] ?? null;

            if ($paymentId !== null) {
                // A client retrying after a dropped connection sends the same
                // ULID; that must settle the invoice once, not twice.
                $existing = Payment::query()->find($paymentId);

                if ($existing !== null) {
                    return $existing;
                }
            }

            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOr($invoiceId, callback: fn () => throw BusinessException::invoiceNotFound($invoiceId));

            if ($invoice->isVoid()) {
                throw BusinessException::invoiceIsVoid($invoice->number);
            }

            $invoiceCurrency = Currency::of($invoice->currency);
            $currency = Currency::of((string) ($data['currency'] ?? $invoice->currency));

            if (! $currency->equals($invoiceCurrency)) {
                throw BusinessException::currencyMismatch($currency->code, $invoiceCurrency->code);
            }

            $amount = new Money((int) $data['amount'], $currency);

            if (! $amount->isPositive()) {
                throw BusinessException::nonPositivePayment();
            }

            $outstanding = $invoice->outstanding();

            if (! $outstanding->isPositive()) {
                throw BusinessException::invoiceAlreadySettled($invoice->number);
            }

            if ($amount->greaterThan($outstanding)) {
                throw BusinessException::overpayment(
                    $invoice->number,
                    $amount->minorUnits,
                    $outstanding->minorUnits,
                );
            }

            $paidAt = $this->toDateTime($data['paid_at'] ?? null);

            $transaction = $this->record->handle([
                'type' => $invoice->isSale() ? Transaction::TYPE_INCOME : Transaction::TYPE_EXPENSE,
                'account_id' => $data['account_id'],
                'category_id' => $data['category_id'] ?? null,
                'amount' => $amount->minorUnits,
                'currency' => $currency->code,
                'occurred_at' => $paidAt,
                'description' => 'Invoice '.$invoice->number,
                'reference' => $invoice->number,
                'payee' => $invoice->contact?->name,
            ] + $this->projectTag($invoice));

            $payment = new Payment;

            if ($paymentId !== null) {
                $payment->id = $paymentId;
            }

            $payment->fill([
                'invoice_id' => $invoice->id,
                'contact_id' => $invoice->contact_id,
                'account_id' => $data['account_id'],
                'amount' => $amount->minorUnits,
                'currency' => $currency->code,
                'paid_at' => $paidAt,
                'method' => $data['method'] ?? 'cash',
                'transaction_id' => $transaction->id,
                'reference' => $data['reference'] ?? null,
            ]);

            $payment->save();

            $invoice->refreshPaymentStatus();

            $saved = $payment->fresh(['invoice', 'transaction', 'account']);

            if ($saved === null) {
                // The payment we just wrote is gone; returning the in-memory
                // copy would claim an invoice was settled by a row that is not
                // there.
                throw BusinessException::paymentNotFound($payment->id);
            }

            return $saved;
        });
    }

    /**
     * Carries the project onto the ledger posting, so project reporting can see
     * money that arrived through an invoice as well as money spent directly.
     *
     * @return array{tags?: list<string>}
     */
    private function projectTag(Invoice $invoice): array
    {
        return $invoice->project_id === null
            ? []
            : ['tags' => [Project::tagFor($invoice->project_id)]];
    }

    private function toDateTime(mixed $value): DateTimeInterface
    {
        return match (true) {
            $value === null, $value === '' => new DateTimeImmutable,
            $value instanceof DateTimeInterface => $value,
            default => new DateTimeImmutable((string) $value),
        };
    }
}
