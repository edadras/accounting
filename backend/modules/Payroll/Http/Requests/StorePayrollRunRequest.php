<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Requests\Concerns\AuthorizesWriters;

final class StorePayrollRunRequest extends FormRequest
{
    use AuthorizesWriters;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'string', 'size:26'],
            'reference' => ['nullable', 'string', 'max:64'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'pay_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', Rule::in(Currency::codes())],
            'account_id' => ['required', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'],

            // Empty means "everyone employed in this period"; a leaver is
            // excluded either way.
            'employee_ids' => ['nullable', 'array', 'max:1000'],
            'employee_ids.*' => ['string', 'size:26'],

            // Keyed by employee id: one-off earnings and deductions for this
            // run only. Integer minor units, never a float.
            'adjustments' => ['nullable', 'array'],
            'adjustments.*.earnings' => ['nullable', 'array', 'max:50'],
            'adjustments.*.earnings.*.code' => ['nullable', 'string', 'max:64'],
            'adjustments.*.earnings.*.label' => ['nullable', 'string', 'max:255'],
            'adjustments.*.earnings.*.amount' => ['required', 'integer', 'min:0'],
            'adjustments.*.deductions' => ['nullable', 'array', 'max:50'],
            'adjustments.*.deductions.*.code' => ['nullable', 'string', 'max:64'],
            'adjustments.*.deductions.*.label' => ['nullable', 'string', 'max:255'],
            'adjustments.*.deductions.*.amount' => ['required', 'integer', 'min:0'],

            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
