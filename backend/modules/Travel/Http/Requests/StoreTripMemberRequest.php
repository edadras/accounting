<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\WorkspaceMember;

final class StoreTripMemberRequest extends FormRequest
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

            // Null for a member who is only a contact, not a user of the app.
            'user_id' => ['nullable', 'integer'],

            'display_name' => ['required', 'string', 'max:120'],
            'weight' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
