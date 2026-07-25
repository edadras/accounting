<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Carbon\CarbonImmutable;

/**
 * A proposed transaction. Never a transaction.
 *
 * The governing rule of docs/08-ai-layer.md is that the AI layer does not
 * write financial data — it suggests, and the user decides. That rule is easy
 * to state and easy to erode, so the parse pipeline cannot express its result
 * as anything but this object: there is no code path from a parse to the
 * ledger that does not go through a user confirming a draft.
 */
final readonly class TransactionDraft
{
    /**
     * @param  array{id: string|null, path: string|null, confidence: float, reason: string}|null  $categorySuggestion
     * @param  array{id: string, name: string, currency: string, reason: string}|null  $accountSuggestion
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $type,
        public int $amount,
        public string $currency,
        public CarbonImmutable $occurredAt,
        public ?string $description,
        public ?array $categorySuggestion,
        public ?array $accountSuggestion,
        public float $confidence,
        public array $warnings = [],
        public array $meta = [],
    ) {}

    public function needsConfirmation(): bool
    {
        return true;
    }

    /** Below the threshold the client shows a form to fill in, not a one-tap confirm. */
    public function isLowConfidence(): bool
    {
        return $this->confidence < (float) config('ai.confidence_threshold', 0.7);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'description' => $this->description,
            'category_suggestion' => $this->categorySuggestion,
            'account_suggestion' => $this->accountSuggestion,
            'confidence' => round($this->confidence, 3),
            'needs_confirmation' => $this->needsConfirmation(),
            'low_confidence' => $this->isLowConfidence(),
            'warnings' => $this->warnings,
            'meta' => $this->meta,
        ];
    }
}
