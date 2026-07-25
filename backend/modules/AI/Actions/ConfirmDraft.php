<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Models\AiDraft;
use Modules\Ledger\Actions\RecordTransaction;
use Modules\Ledger\Models\Transaction;

/**
 * The one place a suggestion becomes money.
 *
 * Everything the AI layer produces stops at a draft; this action is the door,
 * and it is only ever opened by a user request. The overrides matter as much
 * as the write: the user is confirming what they see on screen, which may not
 * be what was suggested, so the values they send win over the draft.
 */
final readonly class ConfirmDraft
{
    public function __construct(private RecordTransaction $record) {}

    /**
     * @param  array{account_id?: string, category_id?: string|null, amount?: int, currency?: string, occurred_at?: string, description?: string|null, type?: string}  $overrides
     */
    public function handle(AiDraft $draft, array $overrides = []): Transaction
    {
        if (! $draft->isPending()) {
            throw AiException::draftAlreadyResolved($draft->id, (string) $draft->status);
        }

        $payload = $draft->payload ?? [];
        $proposed = $draft->kind === AiDraft::KIND_RECEIPT
            ? (array) ($payload['transaction'] ?? [])
            : $payload;

        $accountId = $overrides['account_id'] ?? ($proposed['account_suggestion']['id'] ?? null);

        if (! is_string($accountId) || $accountId === '') {
            throw AiException::draftNotConfirmable('An account must be chosen before this draft can be recorded.');
        }

        $categoryId = array_key_exists('category_id', $overrides)
            ? $overrides['category_id']
            : ($proposed['category_suggestion']['id'] ?? null);

        return DB::transaction(function () use ($draft, $proposed, $overrides, $accountId, $categoryId): Transaction {
            $transaction = $this->record->handle([
                'type' => (string) ($overrides['type'] ?? $proposed['type'] ?? Transaction::TYPE_EXPENSE),
                'account_id' => $accountId,
                'category_id' => $categoryId,
                'amount' => (int) ($overrides['amount'] ?? $proposed['amount'] ?? 0),
                'currency' => (string) ($overrides['currency'] ?? $proposed['currency'] ?? ''),
                'occurred_at' => $overrides['occurred_at'] ?? ($proposed['occurred_at'] ?? null),
                'description' => $overrides['description'] ?? ($proposed['description'] ?? null),
                'source' => match ($draft->source) {
                    AiDraft::SOURCE_VOICE => 'voice',
                    AiDraft::SOURCE_OCR => 'ocr',
                    default => 'manual',
                },
                // The draft that produced it, so any transaction can be traced
                // back to what the AI proposed and what the user changed.
                'source_meta' => ['ai_draft_id' => $draft->id, 'confidence' => $draft->confidence],
            ]);

            $draft->forceFill([
                'status' => AiDraft::STATUS_CONFIRMED,
                'transaction_id' => $transaction->id,
                'resolved_at' => now(),
            ])->save();

            return $transaction;
        });
    }

    public function discard(AiDraft $draft): AiDraft
    {
        if (! $draft->isPending()) {
            throw AiException::draftAlreadyResolved($draft->id, (string) $draft->status);
        }

        $draft->forceFill([
            'status' => AiDraft::STATUS_DISCARDED,
            'resolved_at' => now(),
        ])->save();

        return $draft;
    }
}
