<?php

declare(strict_types=1);

namespace Modules\Capture\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Actions\ParseTransactionText;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Models\AiDraft;
use Modules\Capture\Models\CaptureMessage;
use Modules\Capture\Support\DedupeKey;
use Modules\Capture\Support\MessageRecorder;
use Modules\Core\Support\WorkspaceContext;

/**
 * «دیروز ۳۵۰ لیر برای شام پرداخت کردم» → a draft.
 *
 * All of the work already exists in Modules\AI\Actions\ParseTransactionText;
 * what was missing was a way to reach it from a capture client and a record
 * that the sentence arrived. This adds those two things and nothing else — in
 * particular it does not re-parse, re-normalise or re-score anything.
 */
final readonly class CaptureText
{
    public function __construct(
        private WorkspaceContext $context,
        private ParseTransactionText $parse,
        private StoreDraft $store,
        private MessageRecorder $recorder,
    ) {}

    public function handle(string $text, ?int $userId = null, ?CarbonImmutable $now = null): CaptureMessage
    {
        $this->context->require();
        $now ??= CarbonImmutable::now();

        $attributes = [
            'channel' => CaptureMessage::CHANNEL_TEXT,
            'body' => $text,
            'dedupe_key' => DedupeKey::for(CaptureMessage::CHANNEL_TEXT, $text, (string) $userId),
            'received_at' => $now,
            'created_by' => $userId,
        ];

        try {
            $draft = $this->parse->handle($text, ['now' => $now, 'source' => CaptureMessage::CHANNEL_TEXT]);
        } catch (AiException $e) {
            if ($e->errorCode === 'ai_disabled') {
                // A configuration refusal, not an unreadable sentence. The
                // caller has to hear about this one.
                throw $e;
            }

            return $this->recorder->store(array_merge($attributes, [
                'status' => CaptureMessage::STATUS_UNPARSED,
                'reason' => $e->errorCode,
            ]));
        }

        $stored = $this->store->handle($draft, [
            'source' => AiDraft::SOURCE_TEXT,
            'input_text' => $text,
            'created_by' => $userId,
        ]);

        return $this->recorder->store(array_merge($attributes, [
            'status' => CaptureMessage::STATUS_PARSED,
            'parsed' => $draft->toArray(),
            'ai_draft_id' => $stored->id,
        ]));
    }
}
