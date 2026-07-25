<?php

declare(strict_types=1);

namespace Modules\Business\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Business\Exceptions\BusinessException;
use Modules\Business\Support\Decimal;
use Modules\Business\Support\InvoiceLine;
use Modules\Business\Support\InvoiceTotals;

/**
 * Turns a list of line items into the amounts an invoice is issued with.
 *
 * Two invariants hold by construction, not by rounding luck:
 *
 *   sum(line totals)               == subtotal
 *   subtotal - discount + tax      == total
 *
 * They are what make the invoice agree with itself, and they survive awkward
 * numbers because of three decisions:
 *
 *   1. Tax is charged per line, at that line's own rate, on that line's own
 *      discounted amount. Taxing the rounded grand total instead would give a
 *      different answer whenever two rates appear on one invoice, and would be
 *      the wrong answer in every jurisdiction that itemises VAT.
 *   2. An invoice-wide discount is *allocated* across the lines to the last
 *      minor unit (Money::allocateByWeights), so the parts sum back to the
 *      whole. Applying the same percentage to each line independently is what
 *      loses a unit on a discount that does not divide evenly.
 *   3. Every multiplication goes through exact integer arithmetic (Decimal),
 *      never a float.
 */
final readonly class BuildInvoice
{
    /**
     * @param  array{
     *   currency: string,
     *   discount?: int|null,
     *   items: list<array{
     *     description?: string|null,
     *     quantity?: string|int|float|null,
     *     unit_price?: int|null,
     *     discount?: int|null,
     *     tax_rate?: string|int|float|null,
     *     sort_order?: int|null,
     *   }>,
     * }  $data
     */
    public function handle(array $data): InvoiceTotals
    {
        $currency = Currency::of((string) $data['currency']);
        $items = $data['items'];

        if ($items === []) {
            throw BusinessException::invoiceHasNoItems();
        }

        /** @var list<int> $lineTotals */
        $lineTotals = [];
        /** @var list<int> $discounts */
        $discounts = [];

        foreach ($items as $index => $item) {
            $quantity = (string) ($item['quantity'] ?? '1');

            if (Decimal::toScaled($quantity) <= 0) {
                throw BusinessException::invalidQuantity($quantity);
            }

            $unitPrice = (int) ($item['unit_price'] ?? 0);

            if ($unitPrice < 0) {
                throw BusinessException::negativeAmount('unit_price');
            }

            $lineTotal = Decimal::multiply($unitPrice, $quantity);
            $discount = (int) ($item['discount'] ?? 0);

            if ($discount < 0) {
                throw BusinessException::negativeAmount('discount');
            }

            if ($discount > $lineTotal) {
                throw BusinessException::lineDiscountExceedsLine($index, $discount, $lineTotal);
            }

            if (Decimal::toScaled((string) ($item['tax_rate'] ?? '0')) < 0) {
                throw BusinessException::negativeAmount('tax_rate');
            }

            $lineTotals[] = $lineTotal;
            $discounts[] = $discount;
        }

        $discounts = $this->spreadInvoiceDiscount((int) ($data['discount'] ?? 0), $currency, $lineTotals, $discounts);

        $lines = [];
        $subtotal = 0;
        $discount = 0;
        $tax = 0;

        foreach ($items as $index => $item) {
            $taxRate = (string) ($item['tax_rate'] ?? '0');
            $lineTax = Decimal::percentOf($lineTotals[$index] - $discounts[$index], $taxRate);

            $subtotal += $lineTotals[$index];
            $discount += $discounts[$index];
            $tax += $lineTax;

            $lines[] = new InvoiceLine(
                description: (string) ($item['description'] ?? ''),
                quantity: Decimal::toString(Decimal::toScaled((string) ($item['quantity'] ?? '1'))),
                unitPrice: Money::of((int) ($item['unit_price'] ?? 0), $currency),
                discount: Money::of($discounts[$index], $currency),
                taxRate: Decimal::toString(Decimal::toScaled($taxRate)),
                tax: Money::of($lineTax, $currency),
                lineTotal: Money::of($lineTotals[$index], $currency),
                sortOrder: (int) ($item['sort_order'] ?? $index),
            );
        }

        $totals = new InvoiceTotals(
            currency: $currency,
            subtotal: Money::of($subtotal, $currency),
            discount: Money::of($discount, $currency),
            tax: Money::of($tax, $currency),
            total: Money::of($subtotal - $discount + $tax, $currency),
            lines: $lines,
        );

        $totals->assertConsistent();

        return $totals;
    }

    /**
     * Spreads an invoice-wide discount over the lines that still have room for
     * it, adding the share to each line's own discount.
     *
     * Lines already discounted to nothing are left out of the weighting, so a
     * share can never exceed what is left of a line.
     *
     * Returns the per-line discounts with the invoice-wide share folded in,
     * rather than writing through a reference: the caller then has one list of
     * discounts and no way to read a half-updated one.
     *
     * @param  list<int>  $lineTotals
     * @param  list<int>  $discounts
     * @return list<int>
     */
    private function spreadInvoiceDiscount(
        int $invoiceDiscount,
        Currency $currency,
        array $lineTotals,
        array $discounts,
    ): array {
        if ($invoiceDiscount === 0) {
            return $discounts;
        }

        if ($invoiceDiscount < 0) {
            throw BusinessException::negativeAmount('discount');
        }

        $weights = [];
        $positions = [];

        foreach ($lineTotals as $index => $lineTotal) {
            $remaining = $lineTotal - $discounts[$index];

            if ($remaining > 0) {
                $weights[] = $remaining;
                $positions[] = $index;
            }
        }

        $available = array_sum($weights);

        if ($invoiceDiscount > $available) {
            throw BusinessException::discountExceedsSubtotal($invoiceDiscount, $available);
        }

        $shares = Money::of($invoiceDiscount, $currency)->allocateByWeights($weights);

        foreach ($positions as $slot => $index) {
            $discounts[$index] += $shares[$slot]->minorUnits;
        }

        // The loop only ever writes back to offsets that already exist, but
        // that is invisible from outside; re-indexing keeps the list<int> the
        // caller was promised.
        return array_values($discounts);
    }
}
