<?php

declare(strict_types=1);

namespace Modules\Alerts\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Alerts\Models\AlertRule;
use Modules\Alerts\Models\ChannelKeys;

final class StoreAlertRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'string', 'size:26'],
            'type' => ['required', Rule::in(AlertRule::TYPES)],
            'config' => ['sometimes', 'array'],
            'channels' => ['sometimes', 'array'],
            'channels.*' => [Rule::in(ChannelKeys::ALL)],
            'lead_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
