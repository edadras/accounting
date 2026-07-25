<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

/**
 * One band of a progressive schedule: everything above the previous ceiling and
 * up to `upTo` is taxed at `rate`. A null ceiling is the top band.
 */
final readonly class TaxBracket
{
    public function __construct(
        public ?int $upTo,
        public Percentage $rate,
    ) {}

    /** @return array{up_to:int|null,rate:string} */
    public function toArray(): array
    {
        return ['up_to' => $this->upTo, 'rate' => $this->rate->toDecimalString()];
    }
}
