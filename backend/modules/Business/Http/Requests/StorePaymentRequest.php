<?php

declare(strict_types=1);

namespace Modules\Business\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Business\Models\Payment;
use Modules\Core\Models\WorkspaceMember;

final class StorePaymentRequest extends FormRequest
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

            // Required only on the collection endpoint; the nested route takes
            // the invoice from the URL.
            'invoice_id' => [$this->route('id') === null ? 'required' : 'nullable', 'string', 'size:26'],
            'account_id' => ['required', 'string', 'size:26'],
            'category_id' => ['nullable', 'string', 'size:26'],
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', Rule::in(Currency::codes())],
            'paid_at' => ['nullable', 'date'],
            'method' => ['nullable', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.min' => 'A payment must be greater than zero.',
            'amount.integer' => 'Amount must be an integer in the currency\'s minor unit (1234 = 12.34).',
        ];
    }
}
