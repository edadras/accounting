<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Core\Models\WorkspaceMember;

final class SettleTripRequest extends FormRequest
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
            'settled_at' => ['nullable', 'date'],
        ];
    }
}
