<?php

declare(strict_types=1);

namespace Modules\Payroll\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Core\Support\WorkspaceContext;
use Modules\Payroll\Exceptions\PayrollException;
use Modules\Payroll\Models\Employee;

/**
 * Takes somebody onto the payroll, with the compensation they start on.
 *
 * The employee and their first compensation are written in one transaction: an
 * employee nobody can price is not a useful half of a record.
 */
final readonly class RegisterEmployee
{
    public function __construct(
        private WorkspaceContext $context,
        private SetCompensation $compensation,
    ) {}

    /**
     * @param  array{
     *   id?: string,
     *   employee_number?: string|null,
     *   name: string,
     *   job_title?: string|null,
     *   email?: string|null,
     *   phone?: string|null,
     *   national_id?: string|null,
     *   country?: string|null,
     *   status?: string|null,
     *   started_on: string,
     *   ended_on?: string|null,
     *   notes?: string|null,
     *   compensation?: array{amount:int,currency:string,period?:string|null,effective_from?:string|null},
     * }  $data
     */
    public function handle(array $data): Employee
    {
        $workspace = $this->context->require();

        $status = $data['status'] ?? Employee::STATUS_ACTIVE;

        if (! in_array($status, Employee::STATUSES, true)) {
            throw PayrollException::unknownEmployeeStatus((string) $status);
        }

        $startedOn = CarbonImmutable::parse($data['started_on'])->startOfDay();
        $endedOn = isset($data['ended_on']) && $data['ended_on'] !== null
            ? CarbonImmutable::parse($data['ended_on'])->startOfDay()
            : null;

        if ($endedOn !== null && $endedOn->lessThan($startedOn)) {
            throw PayrollException::employmentEndsBeforeItStarts(
                $startedOn->toDateString(),
                $endedOn->toDateString(),
            );
        }

        // No country on the request means the workspace's own, which is the
        // answer in the overwhelming majority of cases and is still only a
        // default — the field stays per-employee.
        $country = strtoupper((string) ($data['country'] ?? config('payroll.default_country', 'XX')));

        return DB::transaction(function () use ($data, $status, $startedOn, $endedOn, $country, $workspace): Employee {
            $employee = new Employee;

            if (! empty($data['id'])) {
                // Client-generated ULID: a record created offline keeps the
                // identity it was created with.
                $employee->id = $data['id'];
            }

            $employee->fill([
                'workspace_id' => $workspace->id,
                'employee_number' => $data['employee_number'] ?? null,
                'name' => $data['name'],
                'job_title' => $data['job_title'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'national_id' => $data['national_id'] ?? null,
                'country' => $country,
                'status' => $status,
                'started_on' => $startedOn,
                'ended_on' => $endedOn,
                'notes' => $data['notes'] ?? null,
            ]);

            $employee->save();

            if (isset($data['compensation'])) {
                $this->compensation->handle($employee, $data['compensation']);
            }

            return $employee->fresh(['compensations']) ?? $employee;
        });
    }
}
