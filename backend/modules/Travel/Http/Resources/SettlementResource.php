<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Travel\Models\Settlement;

/**
 * @mixin Settlement
 */
final class SettlementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'from_member_id' => $this->from_member_id,
            'to_member_id' => $this->to_member_id,
            'amount' => MoneyPayload::from($this->money()),
            'settled_at' => $this->settled_at->toIso8601String(),
            'transaction_id' => $this->transaction_id,
        ];
    }
}
