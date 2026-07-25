<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Modules\AI\Models\AiDraft;
use Modules\AI\Support\ReceiptDraft;
use Modules\AI\Support\TransactionDraft;

/**
 * Puts a suggestion in the queue of things awaiting a human.
 *
 * Note what this class does not do: it never writes to `transactions`. That is
 * ConfirmDraft's job, and only a user action reaches it.
 */
final readonly class StoreDraft
{
    /** @param array{source?: string, input_text?: string|null, document_id?: string|null, created_by?: int|null} $attributes */
    public function handle(TransactionDraft|ReceiptDraft $draft, array $attributes = []): AiDraft
    {
        $isReceipt = $draft instanceof ReceiptDraft;
        $transaction = $isReceipt ? $draft->transaction : $draft;

        return AiDraft::query()->create([
            'kind' => $isReceipt ? AiDraft::KIND_RECEIPT : AiDraft::KIND_TRANSACTION,
            'source' => $attributes['source'] ?? ($isReceipt ? AiDraft::SOURCE_OCR : AiDraft::SOURCE_TEXT),
            'status' => AiDraft::STATUS_PENDING,
            'input_text' => $attributes['input_text'] ?? ($isReceipt ? $draft->rawText : $transaction->description),
            'payload' => $draft->toArray(),
            'warnings' => $draft->warnings,
            'confidence' => $draft->confidence,
            'needs_confirmation' => true,
            'document_id' => $attributes['document_id'] ?? null,
            'created_by' => $attributes['created_by'] ?? null,
        ]);
    }
}
