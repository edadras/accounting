<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Travel\Models\Trip;
use Modules\Travel\Models\TripMember;

/**
 * @mixin Trip
 */
final class TripResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'destination' => $this->destination,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'base_currency' => $this->base_currency,
            'members' => $this->whenLoaded('members', fn () => $this->members->map(
                fn (TripMember $member) => TripMemberResource::present($member)
            )->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
