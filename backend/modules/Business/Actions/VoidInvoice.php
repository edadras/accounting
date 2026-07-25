<?php

declare(strict_types=1);

namespace Modules\Business\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Business\Exceptions\BusinessException;
use Modules\Business\Models\Invoice;

/**
 * Cancels an invoice without deleting it.
 *
 * A voided invoice stays in the books — its number was issued and must remain
 * accounted for — but stops counting toward any total. An invoice that has
 * already been paid against cannot be voided: the money moved, and pretending
 * otherwise would leave the ledger holding a posting for an invoice that no
 * longer exists.
 */
final readonly class VoidInvoice
{
    public function handle(Invoice|string $invoice, ?string $reason = null): Invoice
    {
        $invoiceId = $invoice instanceof Invoice ? $invoice->id : $invoice;

        return DB::transaction(function () use ($invoiceId, $reason): Invoice {
            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOr($invoiceId, callback: fn () => throw BusinessException::invoiceNotFound($invoiceId));

            if ($invoice->isVoid()) {
                return $invoice;
            }

            if ($invoice->payments()->exists()) {
                throw BusinessException::invoiceHasPayments($invoice->number);
            }

            $invoice->status = Invoice::STATUS_VOID;

            if ($reason !== null) {
                $invoice->notes = trim(($invoice->notes ?? '')."\nVoided: ".$reason);
            }

            $invoice->save();

            return $invoice;
        });
    }
}
