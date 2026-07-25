<?php

declare(strict_types=1);

namespace Modules\Buildings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Buildings\Http\Concerns\PresentsMoney;
use Modules\Buildings\Models\Building;

/**
 * @mixin Building
 */
final class BuildingResource extends JsonResource
{
    use PresentsMoney;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fundBalance = $this->fundBalance();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'units_count' => $this->units_count,
            'charge_formula' => $this->charge_formula,
            'fund_account_id' => $this->fund_account_id,
            'fund_balance' => $fundBalance === null ? null : $this->presentMoney($fundBalance),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
