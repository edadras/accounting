<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Ledger\Models\Transaction;
use Modules\Payroll\Actions\ApprovePayrollRun;
use Modules\Payroll\Actions\CalculatePayslip;
use Modules\Payroll\Actions\CreatePayrollRun;
use Modules\Payroll\Actions\DiscardPayrollRun;
use Modules\Payroll\Actions\MarkPayrollRunPaid;
use Modules\Payroll\Actions\PostPayrollRun;
use Modules\Payroll\Actions\SetCompensation;
use Modules\Payroll\Actions\StoreTaxRuleSet;
use Modules\Payroll\Actions\UpdateEmployee;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Models\PayslipLine;
use Modules\Payroll\Support\CompensationProrator;
use Modules\Payroll\Support\PayPeriod;
use Modules\Payroll\Support\Percentage;
use PHPUnit\Framework\Attributes\Test;

/**
 * The invariants of paying people.
 *
 * A payslip that disagrees with itself is worse than a crash: it is handed to
 * an employee and a tax authority. So the arithmetic is exercised against
 * awkward numbers — a salary that divides by neither twelve nor seven, rates
 * that land on a half unit, a month somebody joined in the middle of — rather
 * than against numbers chosen to come out round.
 */
final class PayrollTest extends PayrollTestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ arithmetic

    #[Test]
    public function net_is_gross_minus_deductions_exactly_for_a_salary_that_divides_evenly_by_nothing(): void
    {
        $workspace = $this->payrollWorkspace('exact@example.test');

        $draft = $this->inWorkspace($workspace, function () {
            $employee = $this->hire('Ada Yılmaz', amount: 1234567);

            return app(CalculatePayslip::class)->handle(
                $employee,
                new PayPeriod('2026-03-01', '2026-03-31'),
                Currency::of('TRY'),
            );
        });

        // 7.5% of 1234567 is 92592.525 and must round half-up on its own, not
        // be truncated and not be recovered later from the total.
        $this->assertSame(92593, $this->lineAmount($draft->lines, 'social_security'));

        // Progressive: 10% of the first 1000000 plus 20% of the 141974 above
        // it. A single blended rate would be a different number.
        $this->assertSame(128395, $this->lineAmount($draft->lines, 'income_tax'));
        $this->assertSame(500, $this->lineAmount($draft->lines, 'stamp_duty'));

        $this->assertSame(1234567, $draft->gross->minorUnits);
        $this->assertSame(221488, $draft->deductionTotal->minorUnits);

        $this->assertSame(
            $draft->gross->minorUnits - $draft->deductionTotal->minorUnits,
            $draft->net->minorUnits,
            'net must be gross minus deductions, exactly, in minor units.',
        );

        $this->assertSame(1013079, $draft->net->minorUnits);

        // The employer's own charges sit outside that identity entirely.
        $this->assertSame(154321, $draft->contributionTotal->minorUnits);
        $this->assertSame(1388888, $draft->employerCost()->minorUnits);
    }

    #[Test]
    public function the_stored_payslip_agrees_with_the_lines_stored_beneath_it(): void
    {
        $workspace = $this->payrollWorkspace('stored@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $run = $this->inWorkspace($workspace, function () use ($account) {
            $this->hire('Ada Yılmaz', amount: 1234567);
            $this->hire('Cem Demir', amount: 999_999);

            return app(CreatePayrollRun::class)->handle($this->runPayload($account));
        });

        $this->inWorkspace($workspace, function () use ($run): void {
            $stored = PayrollRun::query()->with('payslips.lines')->findOrFail($run->id);

            $this->assertCount(2, $stored->payslips);

            foreach ($stored->payslips as $payslip) {
                $earnings = (int) $payslip->lines->where('kind', PayslipLine::KIND_EARNING)->sum('amount');
                $deductions = (int) $payslip->lines->where('kind', PayslipLine::KIND_DEDUCTION)->sum('amount');

                $this->assertSame($earnings, $payslip->gross, 'Every earning must be visible on a line.');
                $this->assertSame($deductions, $payslip->deduction_total);
                $this->assertSame($earnings - $deductions, $payslip->net);
            }

            $this->assertSame((int) $stored->payslips->sum('gross'), $stored->gross_total);
            $this->assertSame((int) $stored->payslips->sum('net'), $stored->net_total);
            $this->assertRunReconciles($stored);
        });
    }

    #[Test]
    public function an_itemised_one_off_deduction_moves_net_by_exactly_its_own_amount(): void
    {
        $workspace = $this->payrollWorkspace('adjust@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        [$plain, $adjusted] = $this->inWorkspace($workspace, function () use ($account): array {
            $employee = $this->hire('Ada Yılmaz', amount: 1234567);

            $calculate = app(CalculatePayslip::class);
            $period = new PayPeriod('2026-03-01', '2026-03-31');

            return [
                $calculate->handle($employee, $period, Currency::of('TRY')),
                $calculate->handle($employee, $period, Currency::of('TRY'), [
                    'deductions' => [['code' => 'advance', 'label' => 'Salary advance', 'amount' => 33_333]],
                ]),
            ];
        });

        $this->assertSame($plain->gross->minorUnits, $adjusted->gross->minorUnits);
        $this->assertSame($plain->net->minorUnits - 33_333, $adjusted->net->minorUnits);
        $this->assertSame(
            $adjusted->gross->minorUnits - $adjusted->deductionTotal->minorUnits,
            $adjusted->net->minorUnits,
        );
    }

    #[Test]
    public function a_bonus_is_taxed_because_it_is_part_of_gross(): void
    {
        $workspace = $this->payrollWorkspace('bonus@example.test');

        [$plain, $withBonus] = $this->inWorkspace($workspace, function (): array {
            $employee = $this->hire('Ada Yılmaz', amount: 1234567);

            $calculate = app(CalculatePayslip::class);
            $period = new PayPeriod('2026-03-01', '2026-03-31');

            return [
                $calculate->handle($employee, $period, Currency::of('TRY')),
                $calculate->handle($employee, $period, Currency::of('TRY'), [
                    'earnings' => [['code' => 'bonus', 'label' => 'March bonus', 'amount' => 250_000]],
                ]),
            ];
        });

        $this->assertSame($plain->gross->minorUnits + 250_000, $withBonus->gross->minorUnits);
        $this->assertGreaterThan(
            $plain->deductionTotal->minorUnits,
            $withBonus->deductionTotal->minorUnits,
            'A bonus that raised gross must have raised the tax charged on it.',
        );
        $this->assertSame(
            $withBonus->gross->minorUnits - $withBonus->deductionTotal->minorUnits,
            $withBonus->net->minorUnits,
        );
    }

    #[Test]
    public function a_twelfth_of_an_annual_salary_paid_twelve_times_is_the_annual_salary(): void
    {
        $workspace = $this->payrollWorkspace('annual@example.test');

        $months = $this->inWorkspace($workspace, function (): array {
            // 1_000_007 divides by twelve with a remainder of eleven, so eleven
            // months must carry one unit more than the twelfth.
            $employee = $this->hire(
                'Ada Yılmaz',
                amount: 1_000_007,
                period: CompensationProrator::PERIOD_ANNUAL,
            );

            $calculate = app(CalculatePayslip::class);
            $amounts = [];

            foreach (range(1, 12) as $month) {
                $draft = $calculate->handle(
                    $employee,
                    PayPeriod::ofMonth(2026, $month),
                    Currency::of('TRY'),
                );

                $amounts[] = $this->lineAmount($draft->lines, 'base_pay');
            }

            return $amounts;
        });

        $this->assertSame(1_000_007, array_sum($months), 'The twelve months must add back up to the year.');
        $this->assertSame(83334, $months[0]);
        $this->assertSame(83333, $months[11]);
    }

    #[Test]
    public function half_a_month_worked_plus_the_other_half_is_a_whole_month(): void
    {
        $workspace = $this->payrollWorkspace('prorate@example.test');

        [$joiner, $leaver, $whole] = $this->inWorkspace($workspace, function (): array {
            $calculate = app(CalculatePayslip::class);
            $period = new PayPeriod('2026-03-01', '2026-03-31');
            $currency = Currency::of('TRY');

            // 1234567 over 31 days: neither share is a whole number of units,
            // which is exactly where a naive multiplication loses one.
            $mid = $this->hire('Mid Joiner', amount: 1234567, startedOn: '2026-03-17');
            $out = $this->hire('Mid Leaver', amount: 1234567, startedOn: '2020-01-01', endedOn: '2026-03-16');
            $full = $this->hire('Full Month', amount: 1234567);

            return [
                $calculate->handle($mid, $period, $currency)->gross->minorUnits,
                $calculate->handle($out, $period, $currency)->gross->minorUnits,
                $calculate->handle($full, $period, $currency)->gross->minorUnits,
            ];
        });

        // 15 of 31 days and 16 of 31 days: neither share is exact on its own.
        $this->assertSame(597371, $joiner);
        $this->assertSame(637196, $leaver);
        $this->assertSame(1234567, $whole);

        $this->assertSame(
            $whole,
            $joiner + $leaver,
            'The two halves of a split month must reconstitute the month, to the unit.',
        );
    }

    #[Test]
    public function a_percentage_is_applied_by_integer_arithmetic_and_rounds_half_up(): void
    {
        // 0.5 of a minor unit must go up, not to the nearest even, and not down
        // because a float said 0.49999999.
        $this->assertSame(5, Percentage::of('9')->applyTo(Money::of(50, 'TRY'))->minorUnits);
        $this->assertSame(1, Percentage::of('10')->applyTo(Money::of(5, 'TRY'))->minorUnits);
        $this->assertSame(92593, Percentage::of('7.5')->applyTo(Money::of(1234567, 'TRY'))->minorUnits);

        // A rate that arrives from JSON as a float is normalised once.
        $this->assertSame('7.5', Percentage::of(7.5)->toDecimalString());
        $this->assertSame(0, Percentage::of('0')->applyTo(Money::of(999, 'TRY'))->minorUnits);
    }

    #[Test]
    public function a_rate_with_more_precision_than_the_schedule_holds_is_refused(): void
    {
        $this->expectException(PayrollException::class);

        Percentage::of('7.123456');
    }

    // ------------------------------------------------------------------- tax

    #[Test]
    public function a_workspace_can_configure_its_own_country_and_gets_its_own_numbers(): void
    {
        $workspace = $this->payrollWorkspace('country@example.test');

        [$generic, $local] = $this->inWorkspace($workspace, function (): array {
            app(StoreTaxRuleSet::class)->handle([
                'country' => 'tr',
                'name' => 'Türkiye 2026',
                'effective_from' => '2026-01-01',
                'rules' => [
                    'tax_base' => 'gross',
                    'brackets' => [
                        ['up_to' => 500_000, 'rate' => '15'],
                        ['up_to' => null, 'rate' => '27'],
                    ],
                    'employee_contributions' => [
                        ['code' => 'sgk', 'label' => 'SGK', 'rate' => '14', 'cap' => null],
                    ],
                    'employer_contributions' => [],
                    'fixed_deductions' => [],
                ],
            ]);

            $calculate = app(CalculatePayslip::class);
            $period = new PayPeriod('2026-03-01', '2026-03-31');
            $currency = Currency::of('TRY');

            return [
                $calculate->handle($this->hire('Generic', amount: 1_000_000, country: 'XX'), $period, $currency),
                $calculate->handle($this->hire('Turkish', amount: 1_000_000, country: 'TR'), $period, $currency),
            ];
        });

        // Generic schedule: 7.5% contribution, then 10% on the remainder.
        $this->assertSame(75_000, $this->lineAmount($generic->lines, 'social_security'));
        $this->assertSame(92_500, $this->lineAmount($generic->lines, 'income_tax'));

        // Stored schedule: a different base, different brackets, no fixed fee.
        $this->assertSame(140_000, $this->lineAmount($local->lines, 'sgk'));
        $this->assertSame(75_000 + 135_000, $this->lineAmount($local->lines, 'income_tax'));
        $this->assertSame('Türkiye 2026', $local->taxRulesName);

        // Both still satisfy the one identity that is not negotiable.
        foreach ([$generic, $local] as $draft) {
            $this->assertSame(
                $draft->gross->minorUnits - $draft->deductionTotal->minorUnits,
                $draft->net->minorUnits,
            );
        }
    }

    #[Test]
    public function a_capped_contribution_stops_at_its_ceiling(): void
    {
        $workspace = $this->payrollWorkspace('cap@example.test');

        $draft = $this->inWorkspace($workspace, function () {
            app(StoreTaxRuleSet::class)->handle([
                'country' => 'NL',
                'name' => 'Capped',
                'effective_from' => '2026-01-01',
                'rules' => [
                    'tax_base' => 'gross',
                    'brackets' => [['up_to' => null, 'rate' => '0']],
                    'employee_contributions' => [
                        ['code' => 'pension', 'label' => 'Pension', 'rate' => '10', 'cap' => 50_000],
                    ],
                ],
            ]);

            return app(CalculatePayslip::class)->handle(
                $this->hire('Capped', amount: 2_000_000, country: 'NL'),
                new PayPeriod('2026-03-01', '2026-03-31'),
                Currency::of('TRY'),
            );
        });

        // 10% of 2000000 is 200000, but the ceiling is 50000.
        $this->assertSame(50_000, $this->lineAmount($draft->lines, 'pension'));
        $this->assertSame(1_950_000, $draft->net->minorUnits);
    }

    #[Test]
    public function a_schedule_whose_brackets_descend_is_refused_before_it_is_stored(): void
    {
        $workspace = $this->payrollWorkspace('descending@example.test');

        try {
            $this->inWorkspace($workspace, fn () => app(StoreTaxRuleSet::class)->handle([
                'country' => 'DE',
                'effective_from' => '2026-01-01',
                'rules' => [
                    'brackets' => [
                        ['up_to' => 900_000, 'rate' => '10'],
                        ['up_to' => 500_000, 'rate' => '20'],
                    ],
                ],
            ]));

            $this->fail('A schedule with descending brackets must be refused.');
        } catch (PayrollException $e) {
            $this->assertSame('tax_brackets_out_of_order', $e->errorCode);
        }
    }

    #[Test]
    public function a_schedule_denominated_in_another_currency_refuses_to_price_the_payslip(): void
    {
        $workspace = $this->payrollWorkspace('currency@example.test');

        try {
            $this->inWorkspace($workspace, function (): void {
                app(StoreTaxRuleSet::class)->handle([
                    'country' => 'US',
                    'name' => 'Dollar schedule',
                    'currency' => 'USD',
                    'effective_from' => '2026-01-01',
                    'rules' => ['brackets' => [['up_to' => null, 'rate' => '10']]],
                ]);

                app(CalculatePayslip::class)->handle(
                    $this->hire('Dollar Person', amount: 100_000, country: 'US'),
                    new PayPeriod('2026-03-01', '2026-03-31'),
                    Currency::of('TRY'),
                );
            });

            $this->fail('Bracket ceilings in another currency must not be applied silently.');
        } catch (PayrollException $e) {
            $this->assertSame('tax_rule_currency_mismatch', $e->errorCode);
        }
    }

    #[Test]
    public function two_schedules_for_one_country_and_date_are_refused(): void
    {
        $workspace = $this->payrollWorkspace('dupe-rules@example.test');

        $this->expectException(PayrollException::class);

        $this->inWorkspace($workspace, function (): void {
            $payload = [
                'country' => 'FR',
                'effective_from' => '2026-01-01',
                'rules' => ['brackets' => [['up_to' => null, 'rate' => '10']]],
            ];

            app(StoreTaxRuleSet::class)->handle($payload);
            app(StoreTaxRuleSet::class)->handle($payload);
        });
    }

    // ------------------------------------------------------------- employees

    #[Test]
    public function an_employee_whose_employment_ended_is_left_out_of_the_next_run(): void
    {
        $workspace = $this->payrollWorkspace('leaver@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $run = $this->inWorkspace($workspace, function () use ($account) {
            $this->hire('Still Here', amount: 500_000);

            $leaver = $this->hire('Long Gone', amount: 500_000);
            app(UpdateEmployee::class)->handle($leaver, ['ended_on' => '2026-01-31']);

            return app(CreatePayrollRun::class)->handle($this->runPayload($account));
        });

        $this->inWorkspace($workspace, function () use ($run): void {
            $stored = PayrollRun::query()->with('payslips.employee')->findOrFail($run->id);

            $this->assertCount(1, $stored->payslips);
            $this->assertSame('Still Here', $stored->payslips->first()?->employee?->name);
        });
    }

    #[Test]
    public function ending_an_employment_marks_the_employee_ended_without_being_told_to(): void
    {
        $workspace = $this->payrollWorkspace('ending@example.test');

        $employee = $this->inWorkspace($workspace, function () {
            $employee = $this->hire('Leaving Soon', amount: 500_000);

            return app(UpdateEmployee::class)->handle($employee, ['ended_on' => '2026-02-28']);
        });

        $this->assertSame(Employee::STATUS_ENDED, $employee->status);
        $this->assertTrue($employee->hasEnded());
        $this->assertFalse($employee->wasEmployedBetween('2026-03-01', '2026-03-31'));
    }

    #[Test]
    public function naming_a_departed_employee_in_a_run_is_refused_rather_than_ignored(): void
    {
        $workspace = $this->payrollWorkspace('named-leaver@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        try {
            $this->inWorkspace($workspace, function () use ($account): void {
                $leaver = $this->hire('Long Gone', amount: 500_000);
                app(UpdateEmployee::class)->handle($leaver, ['ended_on' => '2026-01-31']);

                app(CreatePayrollRun::class)->handle($this->runPayload($account, [
                    'employee_ids' => [$leaver->id],
                ]));
            });

            $this->fail('A leaver named in a run must be refused, not silently dropped.');
        } catch (PayrollException $e) {
            $this->assertSame('employee_not_employable', $e->errorCode);
        }
    }

    #[Test]
    public function a_raise_does_not_reprice_the_payslips_already_issued_at_the_old_rate(): void
    {
        $workspace = $this->payrollWorkspace('raise@example.test');

        [$before, $after] = $this->inWorkspace($workspace, function (): array {
            $employee = $this->hire('Ada Yılmaz', amount: 1_000_000);

            app(SetCompensation::class)->handle($employee, [
                'amount' => 1_500_000,
                'currency' => 'TRY',
                'period' => CompensationProrator::PERIOD_MONTHLY,
                'effective_from' => '2026-04-01',
            ]);

            $employee = $employee->fresh(['compensations']) ?? $employee;
            $calculate = app(CalculatePayslip::class);

            return [
                $calculate->handle($employee, PayPeriod::ofMonth(2026, 3), Currency::of('TRY')),
                $calculate->handle($employee, PayPeriod::ofMonth(2026, 4), Currency::of('TRY')),
            ];
        });

        $this->assertSame(1_000_000, $before->gross->minorUnits);
        $this->assertSame(1_500_000, $after->gross->minorUnits);
    }

    #[Test]
    public function an_employee_paid_in_another_currency_cannot_be_slipped_into_the_run(): void
    {
        $workspace = $this->payrollWorkspace('fx@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        try {
            $this->inWorkspace($workspace, function () use ($account): void {
                $this->hire('Euro Person', amount: 300_000, currency: 'EUR');

                app(CreatePayrollRun::class)->handle($this->runPayload($account));
            });

            $this->fail('Two currencies must not be added together implicitly.');
        } catch (PayrollException $e) {
            $this->assertSame('compensation_currency_mismatch', $e->errorCode);
        }
    }

    #[Test]
    public function an_employee_with_no_compensation_in_force_stops_the_run(): void
    {
        $workspace = $this->payrollWorkspace('unpriced@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        try {
            $this->inWorkspace($workspace, function () use ($account): void {
                Employee::query()->create([
                    'name' => 'Unpriced',
                    'country' => 'XX',
                    'status' => Employee::STATUS_ACTIVE,
                    'started_on' => '2020-01-01',
                ]);

                app(CreatePayrollRun::class)->handle($this->runPayload($account));
            });

            $this->fail('Someone with no rate cannot be paid.');
        } catch (PayrollException $e) {
            $this->assertSame('missing_compensation', $e->errorCode);
        }
    }

    // ------------------------------------------------------------ the ledger

    #[Test]
    public function a_draft_run_posts_nothing_to_the_ledger(): void
    {
        $workspace = $this->payrollWorkspace('draft@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $run = $this->inWorkspace($workspace, function () use ($account) {
            $this->hire('Ada Yılmaz', amount: 1234567);

            return app(CreatePayrollRun::class)->handle($this->runPayload($account));
        });

        $this->assertSame(PayrollRun::STATUS_DRAFT, $run->status);
        $this->assertFalse($run->isPosted());
        $this->assertSame(0, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
        $this->assertSame(5_000_000, $account->fresh()?->current_balance);
    }

    #[Test]
    public function approving_a_run_posts_the_net_pay_and_the_withholdings_and_lowers_the_balance(): void
    {
        $workspace = $this->payrollWorkspace('approve@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $run = $this->inWorkspace($workspace, function () use ($account) {
            $this->hire('Ada Yılmaz', amount: 1234567);

            $draft = app(CreatePayrollRun::class)->handle($this->runPayload($account));

            return app(ApprovePayrollRun::class)->handle($draft);
        });

        $this->assertSame(PayrollRun::STATUS_APPROVED, $run->status);
        $this->assertNotNull($run->approved_at);
        $this->assertTrue($run->isPosted());

        $this->inWorkspace($workspace, function () use ($run): void {
            $this->assertSame(2, Transaction::query()->count(), 'A run posts two legs, no more.');

            $net = Transaction::query()->with('entries')->findOrFail($run->net_transaction_id);
            $liability = Transaction::query()->findOrFail($run->liability_transaction_id);

            $this->assertSame(Transaction::TYPE_EXPENSE, $net->type);
            $this->assertSame(1_013_079, $net->amount);
            $this->assertSame(375_809, $liability->amount);
            $this->assertSame($run->reference, $net->reference);
            $this->assertSame('credit', $net->entries->first()?->direction);

            // Net + withholdings + employer charges is what payroll really cost.
            $this->assertSame(1_388_888, $net->amount + $liability->amount);
        });

        $this->assertSame(5_000_000 - 1_388_888, $account->fresh()?->current_balance);
    }

    #[Test]
    public function approving_the_same_run_twice_does_not_post_it_twice(): void
    {
        $workspace = $this->payrollWorkspace('idempotent@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        [$first, $second] = $this->inWorkspace($workspace, function () use ($account): array {
            $this->hire('Ada Yılmaz', amount: 1234567);

            $draft = app(CreatePayrollRun::class)->handle($this->runPayload($account));
            $approve = app(ApprovePayrollRun::class);

            return [$approve->handle($draft), $approve->handle($draft)];
        });

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->net_transaction_id, $second->net_transaction_id);
        $this->assertSame($first->liability_transaction_id, $second->liability_transaction_id);

        $this->assertSame(2, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
        $this->assertSame(5_000_000 - 1_388_888, $account->fresh()?->current_balance);
    }

    #[Test]
    public function posting_a_run_a_second_time_finds_the_transactions_it_already_wrote(): void
    {
        // The belt to the state machine's braces: even reaching the posting
        // action directly must not produce a second pair of transactions,
        // because the idempotency key is derived from the run.
        $workspace = $this->payrollWorkspace('repost@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $ids = $this->inWorkspace($workspace, function () use ($account): array {
            $this->hire('Ada Yılmaz', amount: 1234567);

            $run = app(ApprovePayrollRun::class)->handle(
                app(CreatePayrollRun::class)->handle($this->runPayload($account)),
            );

            $before = [$run->net_transaction_id, $run->liability_transaction_id];

            // Forget the postings, then ask the ledger again with the same keys.
            $run->forceFill(['net_transaction_id' => null, 'liability_transaction_id' => null])->saveQuietly();

            $reposted = app(PostPayrollRun::class)->handle($run->fresh() ?? $run);

            return [$before, [$reposted->net_transaction_id, $reposted->liability_transaction_id]];
        });

        $this->assertSame($ids[0], $ids[1], 'The same run must reuse the transactions it already has.');
        $this->assertSame(2, $this->inWorkspace($workspace, fn () => Transaction::query()->count()));
        $this->assertSame(5_000_000 - 1_388_888, $account->fresh()?->current_balance);
    }

    // ----------------------------------------------------------- immutability

    #[Test]
    public function a_paid_run_refuses_to_be_changed(): void
    {
        $workspace = $this->payrollWorkspace('paid@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $run = $this->inWorkspace($workspace, function () use ($account) {
            $this->hire('Ada Yılmaz', amount: 1234567);

            $draft = app(CreatePayrollRun::class)->handle($this->runPayload($account));
            app(ApprovePayrollRun::class)->handle($draft);

            return app(MarkPayrollRunPaid::class)->handle($draft->id);
        });

        $this->assertSame(PayrollRun::STATUS_PAID, $run->status);
        $this->assertNotNull($run->paid_at);

        $this->inWorkspace($workspace, function () use ($run): void {
            // Straight at the model, which is where the guard has to live if it
            // is to hold for write paths nobody has written yet.
            try {
                PayrollRun::query()->findOrFail($run->id)->update(['notes' => 'tampered']);
                $this->fail('A paid run must refuse an update.');
            } catch (PayrollException $e) {
                $this->assertSame('payroll_run_is_paid', $e->errorCode);
            }

            try {
                PayrollRun::query()->findOrFail($run->id)->recalculateTotals();
                $this->fail('A paid run must refuse to be recalculated.');
            } catch (PayrollException $e) {
                $this->assertSame('payroll_run_is_paid', $e->errorCode);
            }

            try {
                PayrollRun::query()->findOrFail($run->id)->delete();
                $this->fail('A paid run must refuse to be deleted.');
            } catch (PayrollException $e) {
                $this->assertSame('payroll_run_is_paid', $e->errorCode);
            }

            $this->assertNull(
                PayrollRun::query()->findOrFail($run->id)->notes,
                'The refused update must not have reached the database.',
            );
        });
    }

    #[Test]
    public function a_paid_run_cannot_be_approved_again(): void
    {
        $workspace = $this->payrollWorkspace('reapprove@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        try {
            $this->inWorkspace($workspace, function () use ($account): void {
                $this->hire('Ada Yılmaz', amount: 1234567);

                $draft = app(CreatePayrollRun::class)->handle($this->runPayload($account));
                app(ApprovePayrollRun::class)->handle($draft);
                app(MarkPayrollRunPaid::class)->handle($draft->id);

                app(ApprovePayrollRun::class)->handle($draft->id);
            });

            $this->fail('A paid run must not be approvable again.');
        } catch (PayrollException $e) {
            $this->assertSame('payroll_run_is_paid', $e->errorCode);
        }
    }

    #[Test]
    public function a_draft_cannot_skip_approval_and_be_marked_paid(): void
    {
        $workspace = $this->payrollWorkspace('skip@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        try {
            $this->inWorkspace($workspace, function () use ($account): void {
                $this->hire('Ada Yılmaz', amount: 1234567);

                $draft = app(CreatePayrollRun::class)->handle($this->runPayload($account));

                app(MarkPayrollRunPaid::class)->handle($draft->id);
            });

            $this->fail('A draft has posted nothing, so it cannot have been paid.');
        } catch (PayrollException $e) {
            $this->assertSame('payroll_run_not_approved', $e->errorCode);
        }
    }

    #[Test]
    public function only_a_draft_may_be_discarded(): void
    {
        $workspace = $this->payrollWorkspace('discard@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        $this->inWorkspace($workspace, function () use ($account): void {
            $this->hire('Ada Yılmaz', amount: 1234567);

            $draft = app(CreatePayrollRun::class)->handle($this->runPayload($account));

            app(DiscardPayrollRun::class)->handle($draft->id);

            $this->assertSame(0, PayrollRun::query()->count());
            $this->assertSame(0, Payslip::query()->count(), 'Discarding a draft takes its payslips with it.');

            $approved = app(ApprovePayrollRun::class)->handle(
                app(CreatePayrollRun::class)->handle($this->runPayload($account, ['reference' => 'PAY-2026-03-B'])),
            );

            try {
                app(DiscardPayrollRun::class)->handle($approved->id);
                $this->fail('An approved run is in the ledger and cannot simply be deleted.');
            } catch (PayrollException $e) {
                $this->assertSame('payroll_run_not_a_draft', $e->errorCode);
            }
        });
    }

    #[Test]
    public function a_second_run_in_the_same_month_gets_its_own_reference(): void
    {
        $workspace = $this->payrollWorkspace('reference@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 9_000_000);

        [$first, $second] = $this->inWorkspace($workspace, function () use ($account): array {
            $this->hire('Ada Yılmaz', amount: 500_000);

            $create = app(CreatePayrollRun::class);

            return [$create->handle($this->runPayload($account)), $create->handle($this->runPayload($account))];
        });

        $this->assertSame('PAY-2026-03', $first->reference);
        $this->assertSame('PAY-2026-03-2', $second->reference);
    }

    #[Test]
    public function two_workspaces_can_both_hold_run_pay_2026_03(): void
    {
        $first = $this->payrollWorkspace('ws-one@example.test');
        $second = $this->payrollWorkspace('ws-two@example.test');

        $a = $this->inWorkspace($first, function () use ($first) {
            $account = $this->makeAccount($first, 'Bank', 'TRY', 5_000_000);
            $this->hire('One', amount: 500_000);

            return app(CreatePayrollRun::class)->handle($this->runPayload($account));
        });

        $b = $this->inWorkspace($second, function () use ($second) {
            $account = $this->makeAccount($second, 'Bank', 'TRY', 5_000_000);
            $this->hire('Two', amount: 500_000);

            return app(CreatePayrollRun::class)->handle($this->runPayload($account));
        });

        $this->assertSame('PAY-2026-03', $a->reference);
        $this->assertSame('PAY-2026-03', $b->reference);
        $this->assertNotSame($a->workspace_id, $b->workspace_id);
    }

    #[Test]
    public function a_run_with_nobody_to_pay_is_refused(): void
    {
        $workspace = $this->payrollWorkspace('empty@example.test');
        $account = $this->makeAccount($workspace, 'Bank', 'TRY', 5_000_000);

        try {
            $this->inWorkspace($workspace, fn () => app(CreatePayrollRun::class)->handle($this->runPayload($account)));

            $this->fail('A run with no payslips is not a run.');
        } catch (PayrollException $e) {
            $this->assertSame('payroll_run_has_no_employees', $e->errorCode);
        }
    }

    #[Test]
    public function a_run_cannot_be_paid_from_an_account_in_another_currency(): void
    {
        $workspace = $this->payrollWorkspace('account-fx@example.test');
        $account = $this->makeAccount($workspace, 'Euro account', 'EUR', 5_000_000);

        try {
            $this->inWorkspace($workspace, function () use ($account): void {
                $this->hire('Ada Yılmaz', amount: 500_000);

                app(CreatePayrollRun::class)->handle($this->runPayload($account, ['currency' => 'TRY']));
            });

            $this->fail('The account holds another currency; that must be refused.');
        } catch (PayrollException $e) {
            $this->assertSame('payroll_account_currency_mismatch', $e->errorCode);
        }
    }

    #[Test]
    public function a_period_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(PayrollException::class);

        new PayPeriod('2026-03-31', '2026-03-01');
    }

    /**
     * @param  list<\Modules\Payroll\Support\PayslipLineDraft>  $lines
     */
    private function lineAmount(array $lines, string $code): int
    {
        foreach ($lines as $line) {
            if ($line->code === $code) {
                return $line->amount->minorUnits;
            }
        }

        return 0;
    }
}
