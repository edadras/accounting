<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\Subscription;

/** @mixin Subscription */
final class SubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_code' => $this->plan_code,

            // What the workspace is actually entitled to today, which is not
            // always the plan it is nominally on — see effectivePlanCode.
            'effective_plan_code' => $this->effectivePlanCode(),
            'status' => $this->status,
            'on_trial' => $this->isOnTrial(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'renews_at' => $this->renews_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
