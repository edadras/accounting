<?php

declare(strict_types=1);

namespace Modules\Banking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Banking\Models\Loan;
use Modules\Banking\Support\MoneyView;

/**
 * @mixin Loan
 */
final class LoanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bank_id' => $this->bank_id,
            'account_id' => $this->account_id,
            'title' => $this->title,
            'principal' => MoneyView::of($this->principal, $this->currency),
            'outstanding_balance' => MoneyView::of($this->outstanding_balance, $this->currency),
            'currency' => $this->currency,
            'interest_rate' => $this->interest_rate,
            'interest_type' => $this->interest_type,
            'installments_count' => $this->installments_count,
            'start_date' => $this->start_date->toDateString(),
            'penalty_rate' => $this->penalty_rate,
            'status' => $this->status,
            'installments' => $this->whenLoaded(
                'installments',
                fn () => LoanInstallmentResource::collection($this->installments)->resolve($request),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
