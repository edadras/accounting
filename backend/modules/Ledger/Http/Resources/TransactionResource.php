<?php

declare(strict_types=1);

namespace Modules\Ledger\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Ledger\Models\Transaction;

/**
 * @mixin Transaction
 */
final class TransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,

            // Money always travels as {amount, currency, minor_unit} plus its
            // base-currency equivalent and the rate used. The client formats;
            // it never re-derives.
            'amount' => [
                'value' => $this->amount,
                'currency' => $this->currency,
                'minor_unit' => $this->money()->currency->minorUnit,
                'decimal' => $this->money()->toDecimalString(),
            ],
            'base' => [
                'value' => $this->base_amount,
                'currency' => $this->base_currency,
                'minor_unit' => $this->baseMoney()->currency->minorUnit,
                'decimal' => $this->baseMoney()->toDecimalString(),
                'fx_rate' => $this->fx_rate,
            ],

            'account_id' => $this->account_id,
            'counter_account_id' => $this->counter_account_id,
            'category_id' => $this->category_id,

            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->type,
                'currency' => $this->account->currency,
            ]),
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'path' => $this->category->path,
                'color' => $this->category->color,
                'icon' => $this->category->icon,
            ]),
            'entries' => $this->whenLoaded('entries', fn () => $this->entries->map(fn ($entry) => [
                'id' => $entry->id,
                'account_id' => $entry->account_id,
                'direction' => $entry->direction,
                'amount' => $entry->amount,
                'currency' => $entry->currency,
                'base_amount' => $entry->base_amount,
            ])->all()),

            'occurred_at' => $this->occurred_at->toIso8601String(),
            'description' => $this->description,
            'notes' => $this->notes,
            'payee' => $this->payee,
            'reference' => $this->reference,
            'tags' => $this->tags,
            'source' => $this->source,
            'is_reconciled' => $this->is_reconciled,
            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
