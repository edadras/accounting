<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

use App\Core\Money\Money;

/**
 * A proportional charge on pay — social security, unemployment insurance, a
 * pension — optionally capped at a ceiling expressed in minor units.
 *
 * The same shape serves both sides: an employee contribution is withheld from
 * the payslip, an employer contribution is a cost on top of it. Which side a
 * rule sits on is decided by the list it appears in, not by the rule.
 */
final readonly class ContributionRule
{
    public function __construct(
        public string $code,
        public string $label,
        public Percentage $rate,
        public ?int $cap = null,
    ) {}

    public function on(Money $base): Money
    {
        $amount = $this->rate->applyTo($base);

        if ($this->cap !== null && $amount->minorUnits > $this->cap) {
            return Money::of($this->cap, $amount->currency);
        }

        return $amount;
    }

    /** @return array{code:string,label:string,rate:string,cap:int|null} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'rate' => $this->rate->toDecimalString(),
            'cap' => $this->cap,
        ];
    }
}
