<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * What a receipt was read as, field by field, each with its own confidence,
 * plus the one check that matters: does the page add up.
 */
final readonly class ReceiptDraft
{
    /**
     * @param  array<string, array{value: mixed, confidence: float}>  $fields
     * @param  list<array{name: string, quantity: int, unit_price: int, line_total: int}>  $items
     * @param  array{ok: bool, items_total: int, tax: int, expected_total: int, stated_total: int|null, difference: int}  $arithmetic
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $fields,
        public array $items,
        public array $arithmetic,
        public float $confidence,
        public array $warnings,
        public TransactionDraft $transaction,
        public ?string $rawText = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'fields' => $this->fields,
            'items' => $this->items,
            'arithmetic' => $this->arithmetic,
            'confidence' => round($this->confidence, 3),
            'needs_confirmation' => true,
            'low_confidence' => $this->confidence < (float) config('ai.confidence_threshold', 0.7),
            'warnings' => $this->warnings,
            'transaction' => $this->transaction->toArray(),
            'raw_text' => $this->rawText,
        ];
    }
}
