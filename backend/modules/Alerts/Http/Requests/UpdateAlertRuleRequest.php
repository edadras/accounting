<?php

declare(strict_types=1);

namespace Modules\Alerts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Models\ChannelKeys;
use Modules\Core\Models\WorkspaceMember;

/**
 * Everything optional except the thing that cannot change.
 *
 * `type` is what decides which scanner reads the rule and therefore what
 * `config` is even allowed to mean, so changing it would reinterpret the
 * existing config rather than edit it. That is a new rule.
 */
final class UpdateAlertRuleRequest extends FormRequest
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
            'config' => ['sometimes', 'array'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => [Rule::in(ChannelKeys::ALL)],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->has('type')) {
                return;
            }

            $id = $this->route('id');

            // Narrowed before the lookup: `route()` is mixed, and passing an
            // array to find() returns a collection rather than a model.
            $rule = is_string($id) ? AlertRule::query()->find($id) : null;

            if ($rule !== null && $this->input('type') !== $rule->type) {
                $validator->errors()->add(
                    'type',
                    'A rule cannot change type; create a new one instead.',
                );
            }
        });
    }
}
