<?php

declare(strict_types=1);

namespace Modules\Alerts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Alerts\Models\AlertRule;

/** @mixin AlertRule */
final class AlertRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'config' => $this->config ?? [],
            'channels' => $this->channelKeys(),
            'lead_days' => $this->leadDays(),
            'is_active' => $this->is_active,
        ];
    }
}
