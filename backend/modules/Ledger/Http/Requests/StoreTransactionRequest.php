<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Ledger\Models\Transaction;

final class StoreTransactionRequest extends FormRequest
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
            'type' => ['required', Rule::in(Transaction::TYPES)],
            'account_id' => ['required', 'string', 'size:26'],
            'counter_account_id' => ['nullable', 'string', 'size:26', 'different:account_id'],
            'category_id' => ['nullable', 'string', 'size:26'],

            // Integer minor units. A float here would be a rounding bug waiting
            // to happen, so the API simply will not accept one.
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', Rule::in(Currency::codes())],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],

            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'payee' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'source' => ['nullable', Rule::in(Transaction::SOURCES)],
            'source_meta' => ['nullable', 'array'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.integer' => 'Amount must be an integer in the currency\'s minor unit (1234 = 12.34).',
            'amount.min' => 'Amount must be greater than zero; use `type` to express direction.',
        ];
    }
}
