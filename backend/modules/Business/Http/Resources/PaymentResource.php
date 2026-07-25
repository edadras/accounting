<?php

declare(strict_types=1);

namespace Modules\Business\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Business\Http\Concerns\PresentsMoney;
use Modules\Business\Models\Payment;

/**
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    use PresentsMoney;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'contact_id' => $this->contact_id,
            'account_id' => $this->account_id,
            'amount' => $this->presentMoney($this->money()),
            'paid_at' => $this->paid_at->toIso8601String(),
            'method' => $this->method,

            // The ledger posting this payment produced; balances come from
            // there, never from this table.
            'transaction_id' => $this->transaction_id,
            'reference' => $this->reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
