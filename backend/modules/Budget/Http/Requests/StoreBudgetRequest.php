<?php

declare(strict_types=1);

namespace Modules\Budget\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Budget\Models\Budget;
use Modules\Core\Models\WorkspaceMember;

final class StoreBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->attributes->get('workspace_member');

        return $member instanceof WorkspaceMember && $member->canWrite();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'string', 'size:26'],
            'name' => ['required', 'string', 'max:120'],

            'scope' => ['required', Rule::in(Budget::SCOPES)],
            'scope_id' => [
                Rule::requiredIf(fn (): bool => $this->input('scope') !== Budget::SCOPE_OVERALL),
                'nullable', 'string', 'size:26',
            ],

            'period' => ['required', Rule::in(Budget::PERIODS)],
            'starts_at' => ['required', 'date'],
            'ends_at' => [
                // A custom period is defined by nothing but its two ends; without
                // ends_at there is no window to measure spending in.
                Rule::requiredIf(fn (): bool => $this->input('period') === Budget::PERIOD_CUSTOM),
                'nullable', 'date', 'after_or_equal:starts_at',
            ],

            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::in(Currency::codes())],
            'rollover' => ['nullable', 'boolean'],

            'alert_thresholds' => ['nullable', 'array', 'max:10'],
            'alert_thresholds.*' => ['integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.integer' => 'Amount must be an integer in the currency\'s minor unit (1234 = 12.34).',
        ];
    }
}
