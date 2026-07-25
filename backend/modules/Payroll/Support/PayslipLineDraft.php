<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

use App\Core\Money\Money;

/**
 * One itemised line of a payslip before it is written down.
 *
 * `kind` decides which side of the arithmetic the line falls on and is the
 * only thing that does: an earning adds to gross, a deduction is withheld from
 * it, a contribution is the employer's own cost and touches neither.
 */
final readonly class PayslipLineDraft
{
    public const KIND_EARNING = 'earning';

    public const KIND_DEDUCTION = 'deduction';

    public const KIND_CONTRIBUTION = 'contribution';

    public const KINDS = [self::KIND_EARNING, self::KIND_DEDUCTION, self::KIND_CONTRIBUTION];

    public function __construct(
        public string $kind,
        public string $code,
        public string $label,
        public Money $amount,
        public ?Percentage $rate = null,
        public int $sortOrder = 0,
    ) {}

    public function isEarning(): bool
    {
        return $this->kind === self::KIND_EARNING;
    }

    public function isDeduction(): bool
    {
        return $this->kind === self::KIND_DEDUCTION;
    }

    public function isContribution(): bool
    {
        return $this->kind === self::KIND_CONTRIBUTION;
    }

    /** @return array{kind:string,code:string,label:string,amount:int,rate:string|null,sort_order:int} */
    public function toAttributes(): array
    {
        return [
            'kind' => $this->kind,
            'code' => $this->code,
            'label' => $this->label,
            'amount' => $this->amount->minorUnits,
            'rate' => $this->rate?->toDecimalString(),
            'sort_order' => $this->sortOrder,
        ];
    }
}
