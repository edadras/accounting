<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

/**
 * A flat amount withheld regardless of pay — a stamp duty, a union fee.
 * Held in minor units, so it is exact by construction.
 */
final readonly class FixedDeduction
{
    public function __construct(
        public string $code,
        public string $label,
        public int $amount,
    ) {}

    /** @return array{code:string,label:string,amount:int} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'label' => $this->label, 'amount' => $this->amount];
    }
}
