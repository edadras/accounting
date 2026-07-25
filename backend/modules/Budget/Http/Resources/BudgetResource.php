<?php

declare(strict_types=1);

namespace Modules\Budget\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budget\Models\Budget;

/**
 * @mixin Budget
 */
final class BudgetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'scope' => $this->scope,
            'scope_id' => $this->scope_id,
            'period' => $this->period,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),

            // Money always travels as {value, currency, minor_unit, decimal};
            // the client formats, it never re-derives.
            'amount' => [
                'value' => $this->amount,
                'currency' => $this->currency,
                'minor_unit' => $this->money()->currency->minorUnit,
                'decimal' => $this->money()->toDecimalString(),
            ],

            'rollover' => $this->rollover,
            'alert_thresholds' => $this->alertThresholds(),
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
