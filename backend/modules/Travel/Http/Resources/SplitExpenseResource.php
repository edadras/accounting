<?php

declare(strict_types=1);

namespace Modules\Travel\Http\Resources;

use App\Core\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Travel\Models\SplitExpense;
use Modules\Travel\Models\SplitShare;

/**
 * @mixin SplitExpense
 */
final class SplitExpenseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $baseCurrency = $this->trip->base_currency;

        return [
            'id' => $this->id,
            'trip_id' => $this->trip_id,
            'payer_member_id' => $this->payer_member_id,

            'amount' => MoneyPayload::from($this->money()),
            'base' => MoneyPayload::from(Money::of($this->base_amount, $baseCurrency)) + [
                'fx_rate' => $this->fx_rate,
            ],

            'category_id' => $this->category_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'description' => $this->description,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,

            'shares' => $this->whenLoaded('shares', fn () => $this->shares->map(fn (SplitShare $share) => [
                'id' => $share->id,
                'member_id' => $share->member_id,
                'mode' => $share->mode,
                'amount' => MoneyPayload::from(Money::of($share->share_amount, $this->currency)),
                'base' => MoneyPayload::from(Money::of($share->base_share_amount, $baseCurrency)),
            ])->all()),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
