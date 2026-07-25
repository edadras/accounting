<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Requests;

use App\Core\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;

final class StoreTripRequest extends FormRequest
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
            'destination' => ['nullable', 'string', 'max:180'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'base_currency' => ['required', Rule::in(Currency::codes())],

            'members' => ['sometimes', 'array', 'max:100'],
            'members.*.id' => ['sometimes', 'string', 'size:26'],
            'members.*.user_id' => ['nullable', 'integer'],
            'members.*.display_name' => ['required', 'string', 'max:120'],
            'members.*.weight' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
