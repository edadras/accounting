<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Support\CompensationProrator;
use Modules\Payroll\Support\ContributionRule;
use Modules\Payroll\Support\FixedDeduction;
use Modules\Payroll\Support\PayPeriod;
use Modules\Payroll\Support\PayslipDraft;
use Modules\Payroll\Support\PayslipLineDraft;
use Modules\Payroll\Support\TaxRuleSet;

/**
 * Prices one employee's period.
 *
 * The order is the order a payslip is read in, and it matters:
 *   1. earnings — the contractual rate for the days worked, plus anything
 *      added by hand for this run;
 *   2. employee contributions, charged on gross;
 *   3. income tax, charged on whatever base the schedule names;
 *   4. flat deductions;
 *   5. employer contributions, which are a cost and not a withholding.
 *
 * Every step is integer arithmetic on Money. The identity net = gross −
 * deductions is not restored at the end by adjusting something; it holds
 * because net is never computed any other way.
 */
final readonly class CalculatePayslip
{
    public function __construct(
        private ResolveTaxRules $taxRules,
        private CompensationProrator $prorator,
    ) {}

    /**
     * @param  array{
     *   earnings?: list<array{code?:string,label?:string,amount:int}>,
     *   deductions?: list<array{code?:string,label?:string,amount:int}>,
     * }  $adjustments
     */
    public function handle(Employee $employee, PayPeriod $period, Currency $currency, array $adjustments = []): PayslipDraft
    {
        if (! $employee->wasEmployedBetween($period->start, $period->end)) {
            throw PayrollException::employeeNotEmployable($employee->name);
        }

        $compensation = $employee->compensationOn($period->end);

        if ($compensation === null) {
            throw PayrollException::missingCompensation($employee->name);
        }

        $rate = $compensation->money();

        if (! $rate->currency->equals($currency)) {
            throw PayrollException::compensationCurrencyMismatch(
                $employee->name,
                $rate->currency->code,
                $currency->code,
            );
        }

        $workedDays = $period->overlapDays($employee->started_on, $employee->ended_on);

        $rules = $this->taxRules->handle($employee->country, $period->end);
        $rules->assertAppliesTo($currency->code);

        $lines = [];
        $sort = 0;

        // 1. Earnings.
        $lines[] = new PayslipLineDraft(
            kind: PayslipLineDraft::KIND_EARNING,
            code: 'base_pay',
            label: 'Base pay',
            amount: $this->prorator->handle($rate, $compensation->period, $period, $workedDays),
            sortOrder: $sort++,
        );

        foreach ($this->rows($adjustments, 'earnings') as $row) {
            $lines[] = new PayslipLineDraft(
                kind: PayslipLineDraft::KIND_EARNING,
                code: $row['code'],
                label: $row['label'],
                amount: Money::of($row['amount'], $currency),
                sortOrder: $sort++,
            );
        }

        $gross = $this->sumOf($lines, PayslipLineDraft::KIND_EARNING, $currency);

        // 2. What the employee contributes, charged on gross.
        $employeeContributions = Money::zero($currency);

        foreach ($rules->employeeContributions as $rule) {
            $amount = $rule->on($gross);
            $employeeContributions = $employeeContributions->plus($amount);

            $lines[] = new PayslipLineDraft(
                kind: PayslipLineDraft::KIND_DEDUCTION,
                code: $rule->code,
                label: $rule->label,
                amount: $amount,
                rate: $rule->rate,
                sortOrder: $sort++,
            );
        }

        // 3. Income tax on the base the schedule names — never on a base this
        //    code chose for it.
        $taxable = match ($rules->taxBase) {
            TaxRuleSet::BASE_GROSS => $gross,
            default => $gross->minus($employeeContributions),
        };

        $tax = $rules->taxOn($taxable);

        if ($tax->isPositive()) {
            $lines[] = new PayslipLineDraft(
                kind: PayslipLineDraft::KIND_DEDUCTION,
                code: 'income_tax',
                label: 'Income tax',
                amount: $tax,
                sortOrder: $sort++,
            );
        }

        // 4. Flat charges.
        foreach ($rules->fixedDeductions as $fixed) {
            $lines[] = $this->fixedLine($fixed, $currency, $sort++);
        }

        foreach ($this->rows($adjustments, 'deductions') as $row) {
            $lines[] = new PayslipLineDraft(
                kind: PayslipLineDraft::KIND_DEDUCTION,
                code: $row['code'],
                label: $row['label'],
                amount: Money::of($row['amount'], $currency),
                sortOrder: $sort++,
            );
        }

        // 5. The employer's own charges: a cost, not a withholding, so they sit
        //    outside net = gross − deductions entirely.
        foreach ($rules->employerContributions as $rule) {
            $lines[] = new PayslipLineDraft(
                kind: PayslipLineDraft::KIND_CONTRIBUTION,
                code: $rule->code,
                label: $rule->label,
                amount: $this->employerCharge($rule, $gross),
                rate: $rule->rate,
                sortOrder: $sort++,
            );
        }

        return new PayslipDraft(
            employeeId: $employee->id,
            employeeName: $employee->name,
            currency: $currency,
            lines: $lines,
            periodDays: $period->days(),
            workedDays: $workedDays,
            country: strtoupper($employee->country),
            taxRulesName: $rules->name,
        );
    }

    private function employerCharge(ContributionRule $rule, Money $gross): Money
    {
        return $rule->on($gross);
    }

    private function fixedLine(FixedDeduction $fixed, Currency $currency, int $sort): PayslipLineDraft
    {
        return new PayslipLineDraft(
            kind: PayslipLineDraft::KIND_DEDUCTION,
            code: $fixed->code,
            label: $fixed->label,
            amount: Money::of($fixed->amount, $currency),
            sortOrder: $sort,
        );
    }

    /**
     * @param  array<string, mixed>  $adjustments
     * @return list<array{code:string,label:string,amount:int}>
     */
    private function rows(array $adjustments, string $key): array
    {
        $rows = $adjustments[$key] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $amount = (int) ($row['amount'] ?? 0);

            if ($amount < 0) {
                throw PayrollException::negativeAmount($key);
            }

            $code = is_string($row['code'] ?? null) ? $row['code'] : $key;
            $label = is_string($row['label'] ?? null) ? $row['label'] : $code;

            $clean[] = ['code' => $code, 'label' => $label, 'amount' => $amount];
        }

        return $clean;
    }

    /**
     * @param  list<PayslipLineDraft>  $lines
     */
    private function sumOf(array $lines, string $kind, Currency $currency): Money
    {
        $total = Money::zero($currency);

        foreach ($lines as $line) {
            if ($line->kind === $kind) {
                $total = $total->plus($line->amount);
            }
        }

        return $total;
    }
}
