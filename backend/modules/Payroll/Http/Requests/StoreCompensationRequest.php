<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Requests\Concerns\AuthorizesWriters;
use Modules\Payroll\Support\CompensationProrator;

final class StoreCompensationRequest extends FormRequest
{
    use AuthorizesWriters;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', Rule::in(Currency::codes())],
            'period' => ['nullable', Rule::in(CompensationProrator::PERIODS)],
            'effective_from' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.integer' => 'Compensation must be an integer in the currency\'s minor unit (1234 = 12.34).',
        ];
    }
}
