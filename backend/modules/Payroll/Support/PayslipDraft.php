<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Payroll\Exceptions\PayrollException;

/**
 * A priced payslip, before it becomes rows.
 *
 * The single invariant this class exists to hold: **net = gross − deductions,
 * exactly, in minor units**. It is not asserted in a test alone; it is checked
 * here on construction, because a payslip that disagrees with itself is not a
 * display glitch — it is somebody's wages.
 */
final readonly class PayslipDraft
{
    public Money $gross;

    public Money $deductionTotal;

    public Money $contributionTotal;

    public Money $net;

    /**
     * @param  list<PayslipLineDraft>  $lines
     */
    public function __construct(
        public string $employeeId,
        public string $employeeName,
        public Currency $currency,
        public array $lines,
        public int $periodDays,
        public int $workedDays,
        public string $country,
        public string $taxRulesName,
    ) {
        $this->gross = $this->totalOf(fn (PayslipLineDraft $line) => $line->isEarning());
        $this->deductionTotal = $this->totalOf(fn (PayslipLineDraft $line) => $line->isDeduction());
        $this->contributionTotal = $this->totalOf(fn (PayslipLineDraft $line) => $line->isContribution());

        if ($this->deductionTotal->greaterThan($this->gross)) {
            throw PayrollException::deductionsExceedGross(
                $employeeName,
                $this->gross->minorUnits,
                $this->deductionTotal->minorUnits,
            );
        }

        $this->net = $this->gross->minus($this->deductionTotal);

        if ($this->net->minorUnits !== $this->gross->minorUnits - $this->deductionTotal->minorUnits) {
            throw PayrollException::inconsistentPayslip(
                $this->gross->minorUnits,
                $this->deductionTotal->minorUnits,
                $this->net->minorUnits,
            );
        }
    }

    /** What this employee costs the employer: gross plus the employer's own charges. */
    public function employerCost(): Money
    {
        return $this->gross->plus($this->contributionTotal);
    }

    /** @return list<PayslipLineDraft> */
    public function deductions(): array
    {
        return array_values(array_filter($this->lines, fn (PayslipLineDraft $line) => $line->isDeduction()));
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'currency' => $this->currency->code,
            'gross' => $this->gross->minorUnits,
            'deduction_total' => $this->deductionTotal->minorUnits,
            'contribution_total' => $this->contributionTotal->minorUnits,
            'net' => $this->net->minorUnits,
            'period_days' => $this->periodDays,
            'worked_days' => $this->workedDays,
            'country' => $this->country,
            'tax_rules_name' => $this->taxRulesName,
        ];
    }

    /** @param callable(PayslipLineDraft): bool $matches */
    private function totalOf(callable $matches): Money
    {
        $total = Money::zero($this->currency);

        foreach ($this->lines as $line) {
            if ($matches($line)) {
                $total = $total->plus($line->amount);
            }
        }

        return $total;
    }
}
