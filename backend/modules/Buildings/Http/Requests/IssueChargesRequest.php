<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;

final class IssueChargesRequest extends FormRequest
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
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],

            // The total for the whole building, in integer minor units. The
            // module splits it; the client never computes per-unit shares,
            // because two implementations of the same split will disagree.
            'total' => ['required', 'integer', 'min:1'],
            'currency' => ['required', Rule::in(Currency::codes())],
            'due_date' => ['nullable', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'period.regex' => 'Period must be a YYYY-MM month, e.g. 2026-07.',
            'total.integer' => 'Total must be an integer in the currency\'s minor unit (1234 = 12.34).',
        ];
    }
}
