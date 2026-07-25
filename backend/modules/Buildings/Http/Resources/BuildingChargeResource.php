<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Buildings\Http\Concerns\PresentsMoney;
use Modules\Buildings\Models\BuildingCharge;

/**
 * @mixin BuildingCharge
 */
final class BuildingChargeResource extends JsonResource
{
    use PresentsMoney;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'unit_id' => $this->unit_id,
            'period' => $this->period,
            'amount' => $this->presentMoney($this->money()),
            'paid' => $this->presentMoney($this->paidMoney()),
            'remaining' => $this->presentMoney($this->remainingMoney()),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'transaction_id' => $this->transaction_id,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit === null ? null : [
                'id' => $this->unit->id,
                'unit_no' => $this->unit->unit_no,
                'owner_name' => $this->unit->owner_name,
                'tenant_name' => $this->unit->tenant_name,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
