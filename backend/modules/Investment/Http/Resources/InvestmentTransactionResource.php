<?php

declare(strict_types=1);

namespace Modules\Investment\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Investment\Models\InvestmentTransaction;

/**
 * @mixin InvestmentTransaction
 */
final class InvestmentTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'investment_id' => $this->investment_id,
            'action' => $this->action,
            'quantity' => (string) $this->quantity,

            'price' => InvestmentResource::money($this->unitPrice()),
            'fee' => InvestmentResource::money($this->feeMoney()),
            'gross' => InvestmentResource::money($this->grossMoney()),
            'realized_profit' => InvestmentResource::money($this->realizedProfitMoney()),
            'base' => InvestmentResource::money($this->baseMoney()) + ['fx_rate' => $this->fx_rate],

            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'notes' => $this->notes,
            'transaction_id' => $this->transaction_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
