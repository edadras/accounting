<?php

declare(strict_types=1);

namespace Modules\Capture\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Actions\SuggestClassification;
use Modules\AI\Models\AiDraft;
use Modules\AI\Support\AiSwitch;
use Modules\AI\Support\TransactionDraft;
use Modules\Capture\Exceptions\CaptureException;
use Modules\Capture\Models\CaptureMessage;
use Modules\Capture\Parsers\QrParser;
use Modules\Capture\Support\DedupeKey;
use Modules\Capture\Support\MessageRecorder;
use Modules\Capture\Support\ParsedQr;
use Modules\Core\Support\WorkspaceContext;

/**
 * A scanned code becomes a draft, or is refused.
 *
 * Unlike an SMS, an unreadable QR is answered with an error rather than filed
 * as `unparsed`: the user is standing at a till holding a phone, and telling
 * them now that the code was not understood is the only useful answer. The row
 * is still written, marked `rejected`, so a payload format worth supporting can
 * be found later.
 */
final readonly class CaptureQr
{
    public function __construct(
        private WorkspaceContext $context,
        private QrParser $parser,
        private SuggestClassification $suggest,
        private StoreDraft $store,
        private MessageRecorder $recorder,
    ) {}

    public function handle(string $payload, ?int $userId = null, ?CarbonImmutable $now = null): CaptureMessage
    {
        $workspace = $this->context->require();
        $now ??= CarbonImmutable::now();

        $attributes = [
            'channel' => CaptureMessage::CHANNEL_QR,
            'body' => $payload,
            'dedupe_key' => DedupeKey::for(CaptureMessage::CHANNEL_QR, $payload),
            'received_at' => $now,
            'created_by' => $userId,
        ];

        $earlier = $this->recorder->duplicateOf(CaptureMessage::CHANNEL_QR, $attributes['dedupe_key']);

        if ($earlier !== null) {
            return $this->recorder->storeDuplicate($attributes, $earlier);
        }

        try {
            $parsed = $this->parser->parse($payload, $now);
        } catch (CaptureException $e) {
            $this->recorder->store(array_merge($attributes, [
                'status' => CaptureMessage::STATUS_REJECTED,
                'reason' => $e->errorCode,
            ]));

            throw $e;
        }

        $draft = $this->draft($parsed, $now, AiSwitch::enabledFor($workspace));

        $stored = $this->store->handle($draft, [
            'source' => AiDraft::SOURCE_TEXT,
            'input_text' => $payload,
            'created_by' => $userId,
        ]);

        return $this->recorder->store(array_merge($attributes, [
            'status' => CaptureMessage::STATUS_PARSED,
            'matched_pattern' => $parsed->format,
            'parsed' => $parsed->toArray(),
            'ai_draft_id' => $stored->id,
        ]));
    }

    private function draft(ParsedQr $parsed, CarbonImmutable $now, bool $aiEnabled): TransactionDraft
    {
        $description = $parsed->merchant ?? $parsed->reference ?? 'qr';
        $category = $aiEnabled ? $this->suggest->category($description, $parsed->type) : null;
        $account = $this->suggest->account($parsed->currency);

        $warnings = [];

        if (! $parsed->currencyExplicit) {
            $warnings[] = 'currency_assumed';
        }

        if ($parsed->merchant === null) {
            $warnings[] = 'merchant_missing';
        }

        if ($account === null) {
            $warnings[] = 'no_account_in_currency';
        }

        return new TransactionDraft(
            type: $parsed->type,
            amount: $parsed->amount,
            currency: $parsed->currency,
            occurredAt: $parsed->occurredAt ?? $now->startOfDay(),
            description: $description,
            categorySuggestion: $category,
            accountSuggestion: $account,
            confidence: $this->score($parsed, $account !== null, $category),
            warnings: $warnings,
            meta: array_merge($parsed->toArray(), ['source' => CaptureMessage::CHANNEL_QR]),
        );
    }

    /** @param array{confidence: float}|null $category */
    private function score(ParsedQr $parsed, bool $hasAccount, ?array $category): float
    {
        // An EMV payload that passed its own checksum has proved itself in a
        // way a hand-rolled key/value string cannot.
        $score = $parsed->format === ParsedQr::FORMAT_EMV_TLV ? 0.70 : 0.60;

        $score += $parsed->currencyExplicit ? 0.12 : 0.0;
        $score += $parsed->merchant !== null ? 0.06 : 0.0;
        $score += $hasAccount ? 0.02 : 0.0;
        $score += $category !== null ? 0.08 * $category['confidence'] : 0.0;

        return round(min($score, 0.98), 3);
    }
}
