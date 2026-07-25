<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

use App\Core\Money\Money;
use Modules\Payroll\Exceptions\PayrollException;

/**
 * One country's payroll schedule, as data.
 *
 * Payroll tax is country law and changes without asking us, so nothing in the
 * calculation path knows any country's rules: it is handed a rule set — read
 * from the workspace's own `payroll_tax_rules` row, or from config as a
 * fallback — and does arithmetic. Supporting another country is a row, not a
 * release.
 */
final readonly class TaxRuleSet
{
    public const BASE_GROSS = 'gross';

    public const BASE_GROSS_LESS_EMPLOYEE_CONTRIBUTIONS = 'gross_less_employee_contributions';

    public const BASES = [self::BASE_GROSS, self::BASE_GROSS_LESS_EMPLOYEE_CONTRIBUTIONS];

    public const SOURCE_WORKSPACE = 'workspace';

    public const SOURCE_CONFIG = 'config';

    /**
     * @param  list<TaxBracket>  $brackets
     * @param  list<ContributionRule>  $employeeContributions
     * @param  list<ContributionRule>  $employerContributions
     * @param  list<FixedDeduction>  $fixedDeductions
     */
    public function __construct(
        public string $country,
        public string $name,
        public ?string $currency,
        public string $taxBase,
        public array $brackets,
        public array $employeeContributions,
        public array $employerContributions,
        public array $fixedDeductions,
        public string $source = self::SOURCE_CONFIG,
    ) {}

    /**
     * @param  array<string, mixed>  $rules
     */
    public static function fromArray(array $rules, string $country, string $source = self::SOURCE_CONFIG): self
    {
        $taxBase = is_string($rules['tax_base'] ?? null)
            ? $rules['tax_base']
            : self::BASE_GROSS_LESS_EMPLOYEE_CONTRIBUTIONS;

        if (! in_array($taxBase, self::BASES, true)) {
            throw PayrollException::unknownTaxBase($taxBase);
        }

        return new self(
            country: strtoupper($country),
            name: is_string($rules['name'] ?? null) ? $rules['name'] : 'Payroll schedule',
            currency: is_string($rules['currency'] ?? null) ? strtoupper($rules['currency']) : null,
            taxBase: $taxBase,
            brackets: self::readBrackets(self::listOf($rules['brackets'] ?? [])),
            employeeContributions: self::readContributions(self::listOf($rules['employee_contributions'] ?? [])),
            employerContributions: self::readContributions(self::listOf($rules['employer_contributions'] ?? [])),
            fixedDeductions: self::readFixed(self::listOf($rules['fixed_deductions'] ?? [])),
            source: $source,
        );
    }

    /**
     * The progressive tax on $base.
     *
     * Each band is charged on its own slice and rounded on its own, which is
     * the only reading that survives an audit: a single blended rate over the
     * whole amount would agree by accident on round numbers and disagree by a
     * minor unit on every real salary.
     */
    public function taxOn(Money $base): Money
    {
        $tax = Money::zero($base->currency);

        if (! $base->isPositive()) {
            return $tax;
        }

        $floor = 0;

        foreach ($this->brackets as $bracket) {
            $ceiling = $bracket->upTo ?? $base->minorUnits;
            $top = min($ceiling, $base->minorUnits);
            $slice = $top - $floor;

            if ($slice <= 0) {
                break;
            }

            $tax = $tax->plus($bracket->rate->applyTo(Money::of($slice, $base->currency)));
            $floor = $ceiling;

            if ($floor >= $base->minorUnits) {
                break;
            }
        }

        return $tax;
    }

    /** Refuses a payslip the bracket ceilings cannot honestly be read against. */
    public function assertAppliesTo(string $currency): void
    {
        if ($this->currency !== null && $this->currency !== strtoupper($currency)) {
            throw PayrollException::taxRuleCurrencyMismatch($this->country, strtoupper($currency), $this->currency);
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'currency' => $this->currency,
            'tax_base' => $this->taxBase,
            'brackets' => array_map(fn (TaxBracket $b) => $b->toArray(), $this->brackets),
            'employee_contributions' => array_map(fn (ContributionRule $c) => $c->toArray(), $this->employeeContributions),
            'employer_contributions' => array_map(fn (ContributionRule $c) => $c->toArray(), $this->employerContributions),
            'fixed_deductions' => array_map(fn (FixedDeduction $d) => $d->toArray(), $this->fixedDeductions),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<TaxBracket>
     */
    private static function readBrackets(array $rows): array
    {
        $brackets = [];
        $previous = null;

        foreach ($rows as $row) {
            $upTo = isset($row['up_to']) && $row['up_to'] !== null ? (int) $row['up_to'] : null;

            if ($upTo !== null && $upTo < 0) {
                throw PayrollException::negativeAmount('up_to');
            }

            if ($upTo !== null && $previous !== null && $upTo <= $previous) {
                throw PayrollException::bracketsOutOfOrder($upTo, $previous);
            }

            $brackets[] = new TaxBracket($upTo, Percentage::nonNegative(self::scalar($row['rate'] ?? 0)));
            $previous = $upTo;
        }

        return $brackets;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<ContributionRule>
     */
    private static function readContributions(array $rows): array
    {
        $rules = [];

        foreach ($rows as $row) {
            $cap = isset($row['cap']) && $row['cap'] !== null ? (int) $row['cap'] : null;

            if ($cap !== null && $cap < 0) {
                throw PayrollException::negativeAmount('cap');
            }

            $code = (string) self::scalar($row['code'] ?? 'contribution');

            $rules[] = new ContributionRule(
                code: $code,
                label: (string) self::scalar($row['label'] ?? $code),
                rate: Percentage::nonNegative(self::scalar($row['rate'] ?? 0)),
                cap: $cap,
            );
        }

        return $rules;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<FixedDeduction>
     */
    private static function readFixed(array $rows): array
    {
        $deductions = [];

        foreach ($rows as $row) {
            $amount = (int) ($row['amount'] ?? 0);

            if ($amount < 0) {
                throw PayrollException::negativeAmount('amount');
            }

            $code = (string) self::scalar($row['code'] ?? 'deduction');

            $deductions[] = new FixedDeduction(
                code: $code,
                label: (string) self::scalar($row['label'] ?? $code),
                amount: $amount,
            );
        }

        return $deductions;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private static function scalar(mixed $value): int|float|string
    {
        return is_int($value) || is_float($value) || is_string($value) ? $value : '';
    }
}
