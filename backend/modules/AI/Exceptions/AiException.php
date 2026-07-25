<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the AI layer, carrying a machine-readable code and the HTTP
 * status the API should answer with.
 *
 * The code stays English and stable; the message the user sees is translated
 * client-side from that code (docs/05-api-conventions.md).
 */
final class AiException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function disabled(): self
    {
        return new self(
            'ai_disabled',
            'The AI layer is switched off for this workspace.',
            403,
        );
    }

    public static function emptyInput(string $what): self
    {
        return new self('ai_empty_input', "Nothing to read in the supplied {$what}.", 422, ['input' => $what]);
    }

    public static function noAmountFound(string $text): self
    {
        return new self(
            'ai_no_amount_found',
            'No amount could be read from the text.',
            422,
            ['text' => $text],
        );
    }

    public static function unknownTool(string $name): self
    {
        return new self('ai_unknown_tool', "No tool named [{$name}].", 422, ['tool' => $name]);
    }

    public static function providerUnavailable(string $provider, string $reason): self
    {
        return new self(
            'ai_provider_unavailable',
            "The {$provider} provider could not answer: {$reason}",
            503,
            ['provider' => $provider, 'reason' => $reason],
        );
    }

    public static function transcriptionUnavailable(string $path): self
    {
        return new self(
            'ai_transcription_unavailable',
            'This recording cannot be transcribed without a speech model.',
            503,
            ['path' => basename($path)],
        );
    }

    public static function fileNotReadable(string $path): self
    {
        return new self('ai_file_not_readable', 'The file could not be read.', 422, ['path' => basename($path)]);
    }

    public static function draftAlreadyResolved(string $id, string $status): self
    {
        return new self(
            'ai_draft_already_resolved',
            "Draft [{$id}] was already {$status}.",
            409,
            ['id' => $id, 'status' => $status],
        );
    }

    public static function draftNotConfirmable(string $reason): self
    {
        return new self('ai_draft_not_confirmable', $reason, 422, ['reason' => $reason]);
    }

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => request()->header('X-Request-Id'),
            ],
        ], $this->status);
    }
}
