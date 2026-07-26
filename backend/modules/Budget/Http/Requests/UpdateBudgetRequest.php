<?php

declare(strict_types=1);

namespace Modules\Budget\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Budget\Models\Budget;
use Modules\Core\Models\WorkspaceMember;

/**
 * Every field is optional, but the ones that must agree still must agree.
 *
 * `currency` is deliberately absent. A budget's spend is measured in its own
 * currency, so re-denominating an existing one would silently reinterpret every
 * figure already recorded against it — that is a new budget, not an edit.
 */
final class UpdateBudgetRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:120'],

            'scope' => ['sometimes', Rule::in(Budget::SCOPES)],
            'scope_id' => [
                // Only demanded when the request is the thing changing the
                // scope; a request that leaves the scope alone keeps its
                // existing target.
                Rule::requiredIf(fn (): bool => $this->has('scope')
                    && $this->input('scope') !== Budget::SCOPE_OVERALL),
                'nullable', 'string', 'size:26',
            ],

            'period' => ['sometimes', Rule::in(Budget::PERIODS)],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => [
                Rule::requiredIf(fn (): bool => $this->input('period') === Budget::PERIOD_CUSTOM),
                'nullable', 'date',
            ],

            'amount' => ['sometimes', 'integer', 'min:1'],
            'rollover' => ['sometimes', 'boolean'],

            'alert_thresholds' => ['sometimes', 'nullable', 'array', 'max:10'],
            'alert_thresholds.*' => ['integer', 'min:1', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.integer' => 'Amount must be an integer in the currency\'s minor unit (1234 = 12.34).',
            'currency.prohibited' => 'A budget cannot change currency; create a new one instead.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('currency') && $this->input('currency') !== null) {
                $validator->errors()->add('currency', $this->messages()['currency.prohibited']);
            }

            // Checked against the stored window, not just the payload, so
            // moving one end of a custom period cannot invert it.
            $budget = $this->route('id');
            $existing = is_string($budget) ? Budget::query()->find($budget) : null;

            $starts = $this->date('starts_at') ?? $existing?->starts_at;
            $ends = $this->date('ends_at') ?? $existing?->ends_at;

            if ($starts !== null && $ends !== null && $ends < $starts) {
                $validator->errors()->add('ends_at', 'The period must not end before it starts.');
            }
        });
    }
}
