<?php

declare(strict_types=1);

namespace Modules\Capture\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Actions\SuggestClassification;
use Modules\AI\Models\AiDraft;
use Modules\AI\Support\AiSwitch;
use Modules\AI\Support\TransactionDraft;
use Modules\Capture\Models\CaptureMessage;
use Modules\Capture\Parsers\SmsParser;
use Modules\Capture\Support\AccountMatcher;
use Modules\Capture\Support\DedupeKey;
use Modules\Capture\Support\MessageRecorder;
use Modules\Capture\Support\ParsedSms;
use Modules\Core\Support\WorkspaceContext;
use Modules\Ledger\Models\Account;

/**
 * A bank SMS becomes a draft, or becomes a stored `unparsed` message.
 *
 * Those are the only two outcomes. There is no third one where a message that
 * did not match a known shape is read approximately: an amount without a
 * direction, or a direction without a bank, is a guess, and a guess here writes
 * a plausible wrong number into somebody's books.
 */
final readonly class CaptureSms
{
    public function __construct(
        private WorkspaceContext $context,
        private SmsParser $parser,
        private SuggestClassification $suggest,
        private StoreDraft $store,
        private MessageRecorder $recorder,
    ) {}

    public function handle(
        ?string $sender,
        string $body,
        CarbonImmutable $receivedAt,
        ?int $userId = null,
    ): CaptureMessage {
        $workspace = $this->context->require();

        $attributes = [
            'channel' => CaptureMessage::CHANNEL_SMS,
            'sender' => $sender,
            'body' => $body,
            'dedupe_key' => DedupeKey::for(
                CaptureMessage::CHANNEL_SMS,
                (string) $sender,
                $body,
                $receivedAt->utc()->format('Y-m-d H:i'),
            ),
            'received_at' => $receivedAt,
            'created_by' => $userId,
        ];

        $earlier = $this->recorder->duplicateOf(CaptureMessage::CHANNEL_SMS, $attributes['dedupe_key']);

        if ($earlier !== null) {
            return $this->recorder->storeDuplicate($attributes, $earlier);
        }

        $parsed = $this->parser->parse($sender, $body);

        if ($parsed === null) {
            return $this->recorder->store(array_merge($attributes, [
                'status' => CaptureMessage::STATUS_UNPARSED,
                'reason' => 'no_pattern_matched',
            ]));
        }

        $match = AccountMatcher::match($parsed->accountFragment);
        $draft = $this->draft($parsed, $match, $body, $receivedAt, AiSwitch::enabledFor($workspace));

        $stored = $this->store->handle($draft, [
            // AiDraft's own source vocabulary is text/voice/ocr and belongs to
            // that module. Which capture channel this came from lives on the
            // message row and in the draft's meta, so the AI module keeps its
            // invariants and nothing is invented in its enum.
            'source' => AiDraft::SOURCE_TEXT,
            'input_text' => $body,
            'created_by' => $userId,
        ]);

        return $this->recorder->store(array_merge($attributes, [
            'status' => CaptureMessage::STATUS_PARSED,
            'matched_pattern' => $parsed->patternKey,
            'parsed' => array_merge($parsed->toArray(), [
                'account_match' => $match['reason'],
                'account_id' => $match['account']?->id,
            ]),
            'ai_draft_id' => $stored->id,
        ]));
    }

    /** @param array{account: Account|null, reason: string} $match */
    private function draft(
        ParsedSms $parsed,
        array $match,
        string $body,
        CarbonImmutable $receivedAt,
        bool $aiEnabled,
    ): TransactionDraft {
        $type = $parsed->type();
        $description = $this->describe($body);

        // Categorising is the only step that wants a model. With the AI layer
        // switched off the draft is still complete — it just arrives without a
        // suggested label (docs/07-security.md §5.6).
        $category = $aiEnabled ? $this->suggest->category($description, $type) : null;
        $account = AccountMatcher::suggestion($match['account'], $match['reason']);

        $warnings = [];

        if (! $parsed->amount->currencyExplicit) {
            $warnings[] = 'currency_assumed';
        }

        if ($account === null) {
            $warnings[] = $match['reason'];
        }

        if ($parsed->amount->tomanRialFactor !== null) {
            $warnings[] = 'toman_converted_at_'.$parsed->amount->tomanRialFactor;
        }

        return new TransactionDraft(
            type: $type,
            amount: $parsed->amount->minorUnits,
            currency: $parsed->amount->currency,
            occurredAt: $receivedAt,
            description: $description,
            categorySuggestion: $category,
            accountSuggestion: $account,
            confidence: $this->score($parsed, $account !== null, $category),
            warnings: $warnings,
            meta: array_merge($parsed->toArray(), [
                'source' => CaptureMessage::CHANNEL_SMS,
                'received_at' => $receivedAt->toIso8601String(),
            ]),
        );
    }

    /**
     * A bank SMS is machine-written and matched by an exact shape, so it starts
     * high — the uncertainty left is about which account and what to call it,
     * not about the number.
     *
     * @param  array{confidence: float}|null  $category
     */
    private function score(ParsedSms $parsed, bool $accountMatched, ?array $category): float
    {
        $score = 0.60;

        $score += $parsed->amount->currencyExplicit ? 0.15 : 0.0;
        $score += $accountMatched ? 0.10 : 0.0;
        $score += $parsed->balance !== null ? 0.05 : 0.0;
        $score += $category !== null ? 0.08 * $category['confidence'] : 0.0;

        return round(min($score, 0.98), 3);
    }

    private function describe(string $body): string
    {
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_substr($collapsed, 0, 180);
    }
}
