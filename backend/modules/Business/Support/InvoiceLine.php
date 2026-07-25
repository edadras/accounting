<?php

declare(strict_types=1);

namespace Modules\Business\Support;

use App\Core\Money\Money;

/**
 * One priced line of an invoice, after the arithmetic has been done.
 *
 * `lineTotal` is the extended amount — quantity × unit price — before anything
 * is taken off, because the invoice's `subtotal` is the sum of these and the
 * header then shows `subtotal - discount + tax`. `discount` is everything taken
 * off this line, including its share of an invoice-wide discount.
 */
final readonly class InvoiceLine
{
    public function __construct(
        public string $description,
        public string $quantity,
        public Money $unitPrice,
        public Money $discount,
        public string $taxRate,
        public Money $tax,
        public Money $lineTotal,
        public int $sortOrder,
    ) {}

    /** The amount this line was taxed on: its own total less its discount. */
    public function taxable(): Money
    {
        return $this->lineTotal->minus($this->discount);
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPrice->minorUnits,
            'discount' => $this->discount->minorUnits,
            'tax_rate' => $this->taxRate,
            'tax' => $this->tax->minorUnits,
            'line_total' => $this->lineTotal->minorUnits,
            'sort_order' => $this->sortOrder,
        ];
    }
}
