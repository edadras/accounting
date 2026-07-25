<?php

declare(strict_types=1);

namespace Modules\Business\Support;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Business\Exceptions\BusinessException;

/**
 * The finished arithmetic of an invoice: its lines and the four header amounts
 * derived from them.
 */
final readonly class InvoiceTotals
{
    /** @param  list<InvoiceLine>  $lines */
    public function __construct(
        public Currency $currency,
        public Money $subtotal,
        public Money $discount,
        public Money $tax,
        public Money $total,
        public array $lines,
    ) {}

    /**
     * The two invariants the whole module rests on.
     *
     * Checked before anything is written, because an invoice that disagrees
     * with itself is worse than an error: it gets sent to a customer.
     */
    public function assertConsistent(): void
    {
        $lineSum = Money::sum(
            array_map(fn (InvoiceLine $line) => $line->lineTotal, $this->lines),
            $this->currency,
        );

        if (! $lineSum->equals($this->subtotal)) {
            throw BusinessException::inconsistentTotals(
                $this->subtotal->minorUnits,
                $this->discount->minorUnits,
                $this->tax->minorUnits,
                $this->total->minorUnits,
            );
        }

        $expected = $this->subtotal->minus($this->discount)->plus($this->tax);

        if (! $expected->equals($this->total)) {
            throw BusinessException::inconsistentTotals(
                $this->subtotal->minorUnits,
                $this->discount->minorUnits,
                $this->tax->minorUnits,
                $this->total->minorUnits,
            );
        }
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'subtotal' => $this->subtotal->minorUnits,
            'discount' => $this->discount->minorUnits,
            'tax' => $this->tax->minorUnits,
            'total' => $this->total->minorUnits,
            'currency' => $this->currency->code,
        ];
    }
}
