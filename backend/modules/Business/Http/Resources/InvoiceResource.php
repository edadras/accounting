<?php

declare(strict_types=1);

namespace Modules\Business\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Business\Http\Concerns\PresentsMoney;
use Modules\Business\Models\Invoice;
use Modules\Business\Models\InvoiceItem;

/**
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    use PresentsMoney;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = $this->currency;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'direction' => $this->direction,
            'status' => $this->status,
            'is_overdue' => $this->isPastDue(),

            'contact_id' => $this->contact_id,
            'project_id' => $this->project_id,

            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),

            'subtotal' => $this->presentAmount((int) $this->subtotal, $currency),
            'discount' => $this->presentAmount((int) $this->discount, $currency),
            'tax' => $this->presentAmount((int) $this->tax, $currency),
            'total' => $this->presentMoney($this->money()),
            'paid' => $this->presentMoney($this->paid()),
            'outstanding' => $this->presentMoney($this->outstanding()),

            'base' => $this->presentMoney($this->baseMoney()) + ['fx_rate' => $this->fx_rate],

            'notes' => $this->notes,

            'contact' => $this->whenLoaded('contact', fn () => $this->contact === null ? null : [
                'id' => $this->contact->id,
                'name' => $this->contact->name,
                'type' => $this->contact->type,
            ]),
            'project' => $this->whenLoaded('project', fn () => $this->project === null ? null : [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'status' => $this->project->status,
            ]),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(
                fn (InvoiceItem $item) => [
                    'id' => $item->id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $this->presentAmount((int) $item->unit_price, $currency),
                    'discount' => $this->presentAmount((int) $item->discount, $currency),
                    'tax_rate' => $item->tax_rate,
                    'tax' => $this->presentAmount((int) $item->tax, $currency),
                    'line_total' => $this->presentAmount((int) $item->line_total, $currency),
                    'sort_order' => $item->sort_order,
                ],
            )->all()),
            'payments' => $this->whenLoaded('payments', fn () => PaymentResource::collection($this->payments)),

            'version' => $this->version,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
