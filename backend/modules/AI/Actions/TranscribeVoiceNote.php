<?php

declare(strict_types=1);

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Contracts\AiProvider;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Support\AiSwitch;
use Modules\AI\Support\AudioNormalizer;
use Modules\AI\Support\TransactionDraft;
use Modules\Core\Support\WorkspaceContext;

/**
 * Recording → text → the same draft pipeline as typing it would have taken.
 *
 * Voice deliberately adds nothing beyond transcription: whatever was said is
 * turned into words and then handed to ParseTransactionText, so a spoken
 * «دیروز ۳۵۰ لیر» and a typed one produce the same draft and cannot drift
 * apart as the parser improves.
 *
 * The transcript comes back with the draft because speech recognition is
 * wrong often enough that the user has to be able to see what was heard.
 */
final readonly class TranscribeVoiceNote
{
    public function __construct(
        private WorkspaceContext $context,
        private AiProvider $provider,
        private AudioNormalizer $audio,
        private ParseTransactionText $parse,
    ) {}

    /** @param array{now?: CarbonImmutable} $options */
    public function handle(string $path, array $options = []): TransactionDraft
    {
        AiSwitch::assertEnabled($this->context->require());

        if (! is_readable($path)) {
            throw AiException::fileNotReadable($path);
        }

        $prepared = $this->audio->normalize($path);
        $transcript = trim($this->provider->transcribe($prepared['path']));

        if ($prepared['normalized'] && $prepared['path'] !== $path) {
            @unlink($prepared['path']);
        }

        if ($transcript === '') {
            throw AiException::emptyInput('recording');
        }

        $draft = $this->parse->handle($transcript, [
            'source' => 'voice',
            'now' => $options['now'] ?? CarbonImmutable::now(),
        ]);

        return new TransactionDraft(
            type: $draft->type,
            amount: $draft->amount,
            currency: $draft->currency,
            occurredAt: $draft->occurredAt,
            description: $draft->description,
            categorySuggestion: $draft->categorySuggestion,
            accountSuggestion: $draft->accountSuggestion,
            confidence: $draft->confidence,
            warnings: $draft->warnings,
            meta: $draft->meta + [
                'transcript' => $transcript,
                'audio' => [
                    'original' => basename($path),
                    'normalized' => $prepared['normalized'],
                    'note' => $prepared['note'],
                ],
            ],
        );
    }
}
