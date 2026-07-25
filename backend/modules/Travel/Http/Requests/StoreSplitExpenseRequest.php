<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Travel\Models\SplitShare;

final class StoreSplitExpenseRequest extends FormRequest
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
            'payer_member_id' => ['required', 'string', 'size:26'],

            // Integer minor units, never a float: 12.34 USD is 1234.
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', Rule::in(Currency::codes())],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],

            'category_id' => ['nullable', 'string', 'size:26'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            'mode' => ['nullable', Rule::in(SplitShare::MODES)],
            'participants' => ['nullable', 'array', 'max:100'],
            'participants.*.member_id' => ['required', 'string', 'size:26'],
            'participants.*.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'participants.*.weight' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'participants.*.amount' => ['nullable', 'integer', 'min:0'],
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
