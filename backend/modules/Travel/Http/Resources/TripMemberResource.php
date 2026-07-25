<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Travel\Models\TripMember;

/**
 * @mixin TripMember
 */
final class TripMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return self::present($this->resource);
    }

    /** @return array<string, mixed> */
    public static function present(TripMember $member): array
    {
        return [
            'id' => $member->id,
            'trip_id' => $member->trip_id,
            'user_id' => $member->user_id,
            'display_name' => $member->display_name,
            'weight' => $member->weight,
        ];
    }
}
