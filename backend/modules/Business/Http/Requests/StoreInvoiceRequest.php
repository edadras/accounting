<?php

declare(strict_types=1);

namespace Modules\Business\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Business\Models\Invoice;
use Modules\Core\Models\WorkspaceMember;

final class StoreInvoiceRequest extends FormRequest
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
            'number' => ['nullable', 'string', 'max:64'],
            'direction' => ['required', Rule::in(Invoice::DIRECTIONS)],
            'contact_id' => ['nullable', 'string', 'size:26'],
            'project_id' => ['nullable', 'string', 'size:26'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'string', Rule::in(Currency::codes())],
            'fx_rate' => ['nullable', 'numeric', 'gt:0'],

            // Integer minor units throughout. A float would be a rounding bug
            // waiting to happen, so the API will not accept one.
            'discount' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', Rule::in([Invoice::STATUS_DRAFT, Invoice::STATUS_SENT])],
            'notes' => ['nullable', 'string', 'max:5000'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
            'items.*.discount' => ['nullable', 'integer', 'min:0'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.*.unit_price.integer' => 'Unit price must be an integer in the currency\'s minor unit (1234 = 12.34).',
            'discount.integer' => 'Discount must be an integer in the currency\'s minor unit.',
        ];
    }
}
