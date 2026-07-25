<?php

declare(strict_types=1);

namespace Modules\Billing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Billing\Models\Plan;

/** @mixin Plan */
final class PlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'price' => $this->money(),
            'interval' => $this->interval,
            'features' => $this->features,
        ];
    }
}
