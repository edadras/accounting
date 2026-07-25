<?php

declare(strict_types=1);

namespace Modules\Payroll\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A refusal by the payroll rules, carrying a stable machine-readable code.
 *
 * Implements Responsable so the framework renders it without a line in
 * `bootstrap/app.php`; the message a person reads is translated client-side
 * from the code (docs/05-api-conventions.md).
 */
final class PayrollException extends RuntimeException implements Responsable
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    // ------------------------------------------------------------- employees

    public static function employeeNotFound(string $id): self
    {
        return new self('employee_not_found', "Employee [{$id}] does not exist in this workspace.", 404);
    }

    public static function unknownEmployeeStatus(string $status): self
    {
        return new self('unknown_employee_status', "Unknown employee status [{$status}].");
    }

    public static function unknownPayPeriod(string $period): self
    {
        return new self('unknown_pay_period', "Unknown compensation period [{$period}].");
    }

    public static function employmentEndsBeforeItStarts(string $startedOn, string $endedOn): self
    {
        return new self(
            'employment_ends_before_it_starts',
            "Employment ending {$endedOn} cannot start later, on {$startedOn}.",
            422,
            ['started_on' => $startedOn, 'ended_on' => $endedOn],
        );
    }

    public static function employeeNotEmployable(string $name): self
    {
        return new self(
            'employee_not_employable',
            "{$name} was not employed during this period and cannot be paid in this run.",
        );
    }

    public static function missingCompensation(string $name): self
    {
        return new self('missing_compensation', "{$name} has no compensation in force for this period.");
    }

    public static function compensationCurrencyMismatch(string $name, string $given, string $expected): self
    {
        return new self(
            'compensation_currency_mismatch',
            "{$name} is paid in {$given}, but this run is in {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
        );
    }

    // ------------------------------------------------------------------- tax

    public static function taxRulesNotFound(string $country): self
    {
        return new self(
            'tax_rules_not_found',
            "No payroll tax rules are configured for country [{$country}].",
            422,
            ['country' => $country],
        );
    }

    public static function taxRuleNotFound(string $id): self
    {
        return new self('tax_rule_not_found', "Tax rule set [{$id}] does not exist in this workspace.", 404);
    }

    public static function taxRuleCurrencyMismatch(string $country, string $given, string $expected): self
    {
        return new self(
            'tax_rule_currency_mismatch',
            "The [{$country}] tax rules are denominated in {$expected}; this payslip is in {$given}.",
            422,
            ['country' => $country, 'given' => $given, 'expected' => $expected],
        );
    }

    public static function unknownTaxBase(string $base): self
    {
        return new self('unknown_tax_base', "Unknown taxable base [{$base}].");
    }

    public static function invalidRate(string $value): self
    {
        return new self('invalid_rate', "[{$value}] is not a percentage with at most four decimal places.");
    }

    public static function negativeRate(string $value): self
    {
        return new self('negative_rate', "A payroll rate must not be negative, got [{$value}].");
    }

    public static function bracketsOutOfOrder(int $upTo, int $previous): self
    {
        return new self(
            'tax_brackets_out_of_order',
            "Tax bracket ceilings must ascend: {$upTo} follows {$previous}.",
            422,
            ['up_to' => $upTo, 'previous' => $previous],
        );
    }

    public static function duplicateTaxRules(string $country, string $effectiveFrom): self
    {
        return new self(
            'duplicate_tax_rules',
            "Tax rules for [{$country}] effective {$effectiveFrom} already exist in this workspace.",
            409,
            ['country' => $country, 'effective_from' => $effectiveFrom],
        );
    }

    // ------------------------------------------------------------------ runs

    public static function runNotFound(string $id): self
    {
        return new self('payroll_run_not_found', "Payroll run [{$id}] does not exist in this workspace.", 404);
    }

    public static function payslipNotFound(string $id): self
    {
        return new self('payslip_not_found', "Payslip [{$id}] does not exist in this workspace.", 404);
    }

    public static function periodEndsBeforeItStarts(string $start, string $end): self
    {
        return new self(
            'period_ends_before_it_starts',
            "A pay period cannot end ({$end}) before it starts ({$start}).",
            422,
            ['period_start' => $start, 'period_end' => $end],
        );
    }

    public static function runHasNoEmployees(): self
    {
        return new self('payroll_run_has_no_employees', 'No employee was employed during this period.');
    }

    public static function accountNotFound(string $id): self
    {
        return new self('payroll_account_not_found', "Account [{$id}] does not exist in this workspace.", 404);
    }

    public static function categoryNotFound(string $id): self
    {
        return new self('payroll_category_not_found', "Category [{$id}] does not exist in this workspace.", 404);
    }

    public static function accountCurrencyMismatch(string $given, string $expected): self
    {
        return new self(
            'payroll_account_currency_mismatch',
            "The run is in {$given} but the account it pays from holds {$expected}.",
            422,
            ['given' => $given, 'expected' => $expected],
        );
    }

    public static function duplicateRunReference(string $reference): self
    {
        return new self(
            'duplicate_payroll_run_reference',
            "Payroll run [{$reference}] already exists in this workspace.",
            409,
            ['reference' => $reference],
        );
    }

    /** A run that has been paid is history; nothing may edit it, ever. */
    public static function runIsPaid(string $reference): self
    {
        return new self(
            'payroll_run_is_paid',
            "Payroll run [{$reference}] has been paid and can no longer be changed.",
            422,
            ['reference' => $reference],
        );
    }

    public static function runNotApproved(string $reference, string $status): self
    {
        return new self(
            'payroll_run_not_approved',
            "Payroll run [{$reference}] is {$status}; only an approved run can be marked paid.",
            422,
            ['reference' => $reference, 'status' => $status],
        );
    }

    public static function onlyADraftMayBeDiscarded(string $reference, string $status): self
    {
        return new self(
            'payroll_run_not_a_draft',
            "Payroll run [{$reference}] is {$status}; only a draft may be discarded.",
            422,
            ['reference' => $reference, 'status' => $status],
        );
    }

    // ------------------------------------------------------------ arithmetic

    public static function negativeAmount(string $field): self
    {
        return new self('negative_amount', "[{$field}] must not be negative.", 422, ['field' => $field]);
    }

    public static function deductionsExceedGross(string $name, int $gross, int $deductions): self
    {
        return new self(
            'deductions_exceed_gross',
            "{$name}'s deductions ({$deductions}) are larger than the gross pay ({$gross}).",
            422,
            ['gross' => $gross, 'deductions' => $deductions],
        );
    }

    /**
     * The payslip no longer adds up. This is a bug, not user error: it means
     * the arithmetic drifted, so it fails loudly rather than paying somebody
     * an amount the payslip itself disagrees with.
     */
    public static function inconsistentPayslip(int $gross, int $deductions, int $net): self
    {
        return new self(
            'inconsistent_payslip',
            "Payslip does not reconcile: {$gross} - {$deductions} != {$net}.",
            500,
            ['gross' => $gross, 'deductions' => $deductions, 'net' => $net],
        );
    }

    public static function inconsistentRunTotals(int $expected, int $actual): self
    {
        return new self(
            'inconsistent_payroll_run_totals',
            "The run totals disagree with the payslips beneath them: {$expected} != {$actual}.",
            500,
            ['expected' => $expected, 'actual' => $actual],
        );
    }

    /** @param Request $request */
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
