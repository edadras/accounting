<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use Carbon\CarbonImmutable;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Employee;

/**
 * Amends an employee record — including ending their employment.
 *
 * Setting `ended_on` without a status is read as "they have left": the status
 * follows, because the alternative is a record that says the employment ended
 * and is still picked up by the next payroll run.
 */
final readonly class UpdateEmployee
{
    /**
     * @param  array{
     *   employee_number?: string|null,
     *   name?: string,
     *   job_title?: string|null,
     *   email?: string|null,
     *   phone?: string|null,
     *   national_id?: string|null,
     *   country?: string|null,
     *   status?: string|null,
     *   started_on?: string|null,
     *   ended_on?: string|null,
     *   notes?: string|null,
     * }  $data
     */
    public function handle(Employee $employee, array $data): Employee
    {
        foreach (['employee_number', 'name', 'job_title', 'email', 'phone', 'national_id', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $employee->{$field} = $data[$field];
            }
        }

        if (array_key_exists('country', $data) && $data['country'] !== null) {
            $employee->country = strtoupper((string) $data['country']);
        }

        if (array_key_exists('started_on', $data) && $data['started_on'] !== null) {
            $employee->started_on = CarbonImmutable::parse($data['started_on'])->startOfDay();
        }

        $endsEmployment = false;

        if (array_key_exists('ended_on', $data)) {
            $endedOn = $data['ended_on'] === null
                ? null
                : CarbonImmutable::parse($data['ended_on'])->startOfDay();

            $employee->ended_on = $endedOn;
            $endsEmployment = $endedOn !== null;
        }

        if (array_key_exists('status', $data) && $data['status'] !== null) {
            if (! in_array($data['status'], Employee::STATUSES, true)) {
                throw PayrollException::unknownEmployeeStatus((string) $data['status']);
            }

            $employee->status = $data['status'];
        } elseif ($endsEmployment) {
            $employee->status = Employee::STATUS_ENDED;
        }

        if ($employee->ended_on !== null && $employee->ended_on->lessThan($employee->started_on)) {
            throw PayrollException::employmentEndsBeforeItStarts(
                $employee->started_on->toDateString(),
                $employee->ended_on->toDateString(),
            );
        }

        $employee->save();

        return $employee->fresh(['compensations']) ?? $employee;
    }
}
