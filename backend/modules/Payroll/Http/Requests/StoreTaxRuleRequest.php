<?php

declare(strict_types=1);

namespace Modules\Payroll\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Requests\Concerns\AuthorizesWriters;
use Modules\Payroll\Support\TaxRuleSet;

/**
 * The shape of a schedule, not its contents.
 *
 * Whether the brackets ascend and the rates are sane is decided by TaxRuleSet
 * when it parses them — one place, shared with the rows already in the
 * database, rather than a second copy of the rules living in validation.
 */
final class StoreTaxRuleRequest extends FormRequest
{
    use AuthorizesWriters;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'string', 'size:26'],
            'country' => ['required', 'string', 'size:2', 'alpha'],
            'name' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', Rule::in(Currency::codes())],
            'effective_from' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],

            'rules' => ['required', 'array'],
            'rules.tax_base' => ['nullable', Rule::in(TaxRuleSet::BASES)],

            'rules.brackets' => ['nullable', 'array', 'max:20'],
            'rules.brackets.*.up_to' => ['nullable', 'integer', 'min:0'],
            'rules.brackets.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],

            'rules.employee_contributions' => ['nullable', 'array', 'max:20'],
            'rules.employee_contributions.*.code' => ['required', 'string', 'max:64'],
            'rules.employee_contributions.*.label' => ['nullable', 'string', 'max:255'],
            'rules.employee_contributions.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.employee_contributions.*.cap' => ['nullable', 'integer', 'min:0'],

            'rules.employer_contributions' => ['nullable', 'array', 'max:20'],
            'rules.employer_contributions.*.code' => ['required', 'string', 'max:64'],
            'rules.employer_contributions.*.label' => ['nullable', 'string', 'max:255'],
            'rules.employer_contributions.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'rules.employer_contributions.*.cap' => ['nullable', 'integer', 'min:0'],

            'rules.fixed_deductions' => ['nullable', 'array', 'max:20'],
            'rules.fixed_deductions.*.code' => ['required', 'string', 'max:64'],
            'rules.fixed_deductions.*.label' => ['nullable', 'string', 'max:255'],
            'rules.fixed_deductions.*.amount' => ['required', 'integer', 'min:0'],
        ];
    }
}
