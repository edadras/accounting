<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Requests\Concerns\AuthorizesWriters;
use Modules\Payroll\Models\Employee;
use Modules\Payroll\Support\CompensationProrator;

final class StoreEmployeeRequest extends FormRequest
{
    use AuthorizesWriters;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'string', 'size:26'],
            'employee_number' => ['nullable', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'national_id' => ['nullable', 'string', 'max:64'],

            // ISO 3166-1 alpha-2, and the reason tax is pluggable rather than
            // written into the calculation.
            'country' => ['nullable', 'string', 'size:2', 'alpha'],

            'status' => ['nullable', Rule::in(Employee::STATUSES)],
            'started_on' => ['required', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'notes' => ['nullable', 'string', 'max:5000'],

            'compensation' => ['nullable', 'array'],

            // Integer minor units throughout. A float would be a rounding bug
            // waiting to happen, so the API will not accept one.
            'compensation.amount' => ['required_with:compensation', 'integer', 'min:0'],
            'compensation.currency' => ['required_with:compensation', 'string', Rule::in(Currency::codes())],
            'compensation.period' => ['nullable', Rule::in(CompensationProrator::PERIODS)],
            'compensation.effective_from' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'compensation.amount.integer' => 'Compensation must be an integer in the currency\'s minor unit (1234 = 12.34).',
        ];
    }
}
