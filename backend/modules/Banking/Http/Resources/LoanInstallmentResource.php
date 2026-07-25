<?php

declare(strict_types=1);

namespace Modules\Banking\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Banking\Models\LoanInstallment;
use Modules\Banking\Support\MoneyView;

/**
 * @mixin LoanInstallment
 */
final class LoanInstallmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = $this->currencyCode();

        return [
            'id' => $this->id,
            'loan_id' => $this->loan_id,
            'number' => $this->number,
            'due_date' => $this->due_date->toDateString(),
            'principal_part' => MoneyView::of($this->principal_part, $currency),
            'interest_part' => MoneyView::of($this->interest_part, $currency),
            'total_amount' => MoneyView::of($this->total_amount, $currency),
            'paid_amount' => MoneyView::of($this->paid_amount, $currency),
            'penalty_amount' => MoneyView::of($this->penalty_amount, $currency),
            'remaining' => MoneyView::of($this->remaining(), $currency),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'status' => $this->status,
            'transaction_id' => $this->transaction_id,
        ];
    }

    /** An instalment has no currency of its own; it is always the loan's. */
    private function currencyCode(): string
    {
        return (string) ($this->resource->loan->currency ?? 'USD');
    }
}
