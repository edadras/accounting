<?php

declare(strict_types=1);

namespace Modules\Assets\Http\Resources;

use App\Core\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Assets\Models\Asset;

/**
 * @mixin Asset
 */
final class AssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'currency' => $this->currency,

            // Money always travels as {value, currency, minor_unit, decimal}.
            'purchase_price' => self::money($this->purchasePrice()),
            'current_value' => self::money($this->currentValue()),
            'salvage_value' => self::money($this->salvageValue()),
            'purchase_date' => $this->purchase_date->toDateString(),

            'depreciation' => [
                'method' => $this->depreciation_method,
                'rate' => $this->depreciation_rate === null ? null : (string) $this->depreciation_rate,
                'useful_life_years' => $this->useful_life_years,
            ],

            'insurance' => [
                'provider' => $this->insurance_provider,
                'expires_at' => $this->insurance_expires_at?->toDateString(),
                'is_expired' => $this->insuranceExpired(),
            ],

            'notes' => $this->notes,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{value:int,currency:string,minor_unit:int,decimal:string} */
    public static function money(Money $money): array
    {
        return [
            'value' => $money->minorUnits,
            'currency' => $money->currency->code,
            'minor_unit' => $money->currency->minorUnit,
            'decimal' => $money->toDecimalString(),
        ];
    }
}
