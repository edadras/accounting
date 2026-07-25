<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use App\Core\Money\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Models\EmployeeCompensation;
use Modules\Payroll\Support\CompensationProrator;

/**
 * Records what an employee is paid from a given date.
 *
 * A raise closes the previous row rather than overwriting it. Overwriting
 * would silently reprice every payslip that had already been issued at the old
 * rate, which is how a payroll system loses an audit.
 */
final readonly class SetCompensation
{
    /**
     * @param  array{amount:int,currency:string,period?:string|null,effective_from?:string|null}  $data
     */
    public function handle(Employee $employee, array $data): EmployeeCompensation
    {
        $amount = (int) $data['amount'];

        if ($amount < 0) {
            throw PayrollException::negativeAmount('amount');
        }

        $currency = Currency::of($data['currency']);
        $period = $data['period'] ?? CompensationProrator::PERIOD_MONTHLY;

        if (! in_array($period, CompensationProrator::PERIODS, true)) {
            throw PayrollException::unknownPayPeriod((string) $period);
        }

        $effectiveFrom = isset($data['effective_from']) && $data['effective_from'] !== null
            ? CarbonImmutable::parse($data['effective_from'])->startOfDay()
            : $employee->started_on;

        return DB::transaction(function () use ($employee, $amount, $currency, $period, $effectiveFrom): EmployeeCompensation {
            // Close anything still open on the day before the new rate starts,
            // so exactly one rate is ever in force on any given day.
            $employee->compensations()
                ->whereNull('effective_to')
                ->whereDate('effective_from', '<', $effectiveFrom->toDateString())
                ->update(['effective_to' => $effectiveFrom->subDay()->toDateString()]);

            $employee->compensations()
                ->whereDate('effective_from', '>=', $effectiveFrom->toDateString())
                ->delete();

            $compensation = new EmployeeCompensation;

            $compensation->fill([
                'workspace_id' => $employee->workspace_id,
                'employee_id' => $employee->id,
                'amount' => $amount,
                'currency' => $currency->code,
                'period' => $period,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);

            $compensation->save();

            return $compensation;
        });
    }
}
