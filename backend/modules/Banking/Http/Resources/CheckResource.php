<?php

declare(strict_types=1);

namespace Modules\Banking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Banking\Models\Check;
use Modules\Banking\Support\MoneyView;

/**
 * @mixin Check
 */
final class CheckResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'direction' => $this->direction,
            'check_number' => $this->check_number,
            'amount' => MoneyView::of($this->amount, $this->currency),
            'base_amount' => $this->base_amount,
            'due_date' => $this->due_date->toDateString(),
            'status' => $this->status,
            'party_name' => $this->party_name,
            'notes' => $this->notes,
            'transaction_id' => $this->transaction_id,
            'cleared_at' => $this->cleared_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
