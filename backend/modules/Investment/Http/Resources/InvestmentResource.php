<?php

declare(strict_types=1);

namespace Modules\Investment\Http\Resources;

use App\Core\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Investment\Models\Investment;

/**
 * @mixin Investment
 */
final class InvestmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'symbol' => $this->symbol,
            'currency' => $this->currency,

            // A string, not a number: 0.00318 BTC through a JSON float would
            // come back as something slightly else.
            'quantity' => (string) $this->quantity,

            // Money always travels as {value, currency, minor_unit, decimal}.
            // The client formats; it never re-derives.
            'avg_buy_price' => self::money($this->avgBuyPrice()),
            'current_price' => $this->currentPrice() === null ? null : self::money($this->currentPrice()),
            'cost_basis' => self::money($this->costBasis()),
            'current_value' => self::money($this->currentValue()),
            'realized_profit' => self::money($this->realizedProfit()),
            'unrealized_profit' => self::money($this->unrealizedProfit()),
            'total_profit' => self::money($this->totalProfit()),
            'roi' => $this->roi(),

            'priced_at' => $this->priced_at?->toIso8601String(),
            'notes' => $this->notes,
            'transactions' => $this->whenLoaded(
                'transactions',
                fn () => InvestmentTransactionResource::collection($this->transactions),
            ),
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
