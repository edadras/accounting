<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Buildings\Models\BuildingUnit;

/**
 * @mixin BuildingUnit
 */
final class BuildingUnitResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'building_id' => $this->building_id,
            'unit_no' => $this->unit_no,
            'area_m2' => (string) $this->area_m2,
            'residents_count' => $this->residents_count,
            'owner_name' => $this->owner_name,
            'owner_contact' => $this->owner_contact,
            'tenant_name' => $this->tenant_name,
            'tenant_contact' => $this->tenant_contact,
            'share_factor' => (string) $this->share_factor,
            'is_occupied' => $this->is_occupied,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
