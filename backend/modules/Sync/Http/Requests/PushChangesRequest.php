<?php

declare(strict_types=1);

namespace Modules\Sync\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Models\WorkspaceMember;
use Modules\Sync\Models\SyncChange;
use Modules\Sync\Support\SyncRegistry;

final class PushChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->attributes->get('workspace_member');

        return $member instanceof WorkspaceMember && $member->canWrite();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $registry = app(SyncRegistry::class);

        return [
            'device_id' => ['sometimes', 'nullable', 'string', 'max:64'],

            'changes' => ['required', 'array', 'max:'.(int) config('sync.max_batch', 500)],

            // The whitelist, enforced before anything resolves a class. An
            // entity the registry does not know is a refusal, not a lookup.
            'changes.*.entity' => ['required', 'string', Rule::in($registry->keys())],

            'changes.*.id' => ['required', 'string', 'max:64'],
            'changes.*.op' => ['required', 'string', Rule::in(SyncChange::OPERATIONS)],
            'changes.*.base_version' => ['required', 'integer', 'min:0'],
            'changes.*.payload' => ['required_unless:changes.*.op,delete', 'array'],
        ];
    }
}
