<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Requests\Concerns\AuthorizesWriters;
use Modules\Payroll\Models\Employee;

final class UpdateEmployeeRequest extends FormRequest
{
    use AuthorizesWriters;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'country' => ['sometimes', 'string', 'size:2', 'alpha'],
            'status' => ['sometimes', Rule::in(Employee::STATUSES)],
            'started_on' => ['sometimes', 'date'],
            'ended_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
