<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Category;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\PayrollRun;
use Modules\Payroll\Models\Payslip;
use Modules\Payroll\Support\PayPeriod;
use Modules\Payroll\Support\PayslipDraft;

/**
 * Opens a payroll run as a draft, with a payslip for everyone employed in the
 * period.
 *
 * A draft posts nothing. It exists so that the arithmetic can be looked at and
 * argued with before it reaches the books — approving is the step that costs
 * money, and it is a separate one on purpose.
 */
final readonly class CreatePayrollRun
{
    public function __construct(
        private WorkspaceContext $context,
        private CalculatePayslip $calculate,
    ) {}

    /**
     * @param  array{
     *   id?: string,
     *   reference?: string|null,
     *   period_start: string,
     *   period_end: string,
     *   pay_date?: string|null,
     *   currency?: string|null,
     *   account_id: string,
     *   category_id?: string|null,
     *   employee_ids?: list<string>|null,
     *   adjustments?: array<string, array{earnings?: list<array{code?:string,label?:string,amount:int}>, deductions?: list<array{code?:string,label?:string,amount:int}>}>,
     *   notes?: string|null,
     * }  $data
     */
    public function handle(array $data): PayrollRun
    {
        $workspace = $this->context->require();
        $period = new PayPeriod($data['period_start'], $data['period_end']);

        $currency = Currency::of((string) ($data['currency'] ?? $workspace->base_currency));

        $account = Account::query()->findOr(
            $data['account_id'],
            callback: fn () => throw PayrollException::accountNotFound($data['account_id']),
        );

        if (! Currency::of($account->currency)->equals($currency)) {
            throw PayrollException::accountCurrencyMismatch($currency->code, $account->currency);
        }

        $categoryId = $this->resolveCategoryId($data['category_id'] ?? null);
        $employees = $this->employees($period, $data['employee_ids'] ?? null);

        if ($employees === []) {
            throw PayrollException::runHasNoEmployees();
        }

        /** @var array<string, array<string, mixed>> $adjustments */
        $adjustments = $data['adjustments'] ?? [];

        $drafts = [];

        foreach ($employees as $employee) {
            /** @var array{earnings?: list<array{code?:string,label?:string,amount:int}>, deductions?: list<array{code?:string,label?:string,amount:int}>} $forEmployee */
            $forEmployee = $adjustments[$employee->id] ?? [];

            $drafts[] = $this->calculate->handle($employee, $period, $currency, $forEmployee);
        }

        return DB::transaction(function () use ($data, $period, $currency, $account, $categoryId, $drafts): PayrollRun {
            $run = new PayrollRun;

            if (! empty($data['id'])) {
                $run->id = $data['id'];
            }

            $run->fill([
                'reference' => $this->reference($data['reference'] ?? null, $period),
                'period_start' => $period->start,
                'period_end' => $period->end,
                'pay_date' => isset($data['pay_date'])
                    ? CarbonImmutable::parse($data['pay_date'])->startOfDay()
                    : $period->end,
                'currency' => $currency->code,
                'status' => PayrollRun::STATUS_DRAFT,
                'account_id' => $account->id,
                'category_id' => $categoryId,
                'notes' => $data['notes'] ?? null,
            ]);

            $run->save();

            foreach ($drafts as $draft) {
                $this->writePayslip($run, $draft);
            }

            $run->recalculateTotals();

            return $run->fresh(['payslips.lines', 'payslips.employee', 'account']) ?? $run;
        });
    }

    private function writePayslip(PayrollRun $run, PayslipDraft $draft): Payslip
    {
        /** @var Payslip $payslip */
        $payslip = $run->payslips()->create($draft->toAttributes() + [
            'workspace_id' => $run->workspace_id,
        ]);

        foreach ($draft->lines as $line) {
            $payslip->lines()->create($line->toAttributes() + [
                'workspace_id' => $run->workspace_id,
            ]);
        }

        $payslip->assertReconciles();

        return $payslip;
    }

    /**
     * The people this run pays.
     *
     * Somebody whose employment has ended is filtered out by the query, so a
     * caller cannot forget to; naming them explicitly is refused rather than
     * quietly ignored, because a name typed into a payroll request and then
     * dropped in silence is worse than an error.
     *
     * @param  list<string>|null  $employeeIds
     * @return list<Employee>
     */
    private function employees(PayPeriod $period, ?array $employeeIds): array
    {
        if ($employeeIds === null || $employeeIds === []) {
            /** @var list<Employee> $all */
            $all = Employee::query()
                ->employableBetween($period->start, $period->end)
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->all();

            return $all;
        }

        $employees = [];

        foreach (array_unique($employeeIds) as $id) {
            $employee = Employee::query()->findOr($id, callback: fn () => throw PayrollException::employeeNotFound($id));

            if (! $employee->wasEmployedBetween($period->start, $period->end)) {
                throw PayrollException::employeeNotEmployable($employee->name);
            }

            $employees[] = $employee;
        }

        return $employees;
    }

    private function resolveCategoryId(?string $categoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        Category::query()->findOr($categoryId, callback: fn () => throw PayrollException::categoryNotFound($categoryId));

        return $categoryId;
    }

    /**
     * "PAY-2026-03", and "-2" onwards for a second run in the same month, so a
     * bonus run never collides with the salary run.
     */
    private function reference(?string $requested, PayPeriod $period): string
    {
        if ($requested !== null && $requested !== '') {
            if (PayrollRun::query()->withTrashed()->where('reference', $requested)->exists()) {
                throw PayrollException::duplicateRunReference($requested);
            }

            return $requested;
        }

        $format = (string) config('payroll.run_reference.format', '{prefix}-{year}-{month}');
        $prefix = (string) config('payroll.run_reference.prefix', 'PAY');
        $base = $period->reference($format, $prefix);
        $candidate = $base;
        $suffix = 1;

        while (PayrollRun::query()->withTrashed()->where('reference', $candidate)->exists()) {
            $suffix++;
            $candidate = $base.'-'.$suffix;

            if ($suffix > 999) {
                throw PayrollException::duplicateRunReference($base);
            }
        }

        return $candidate;
    }
}
