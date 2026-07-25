<?php

declare(strict_types=1);

namespace Modules\Capture\Exceptions;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal by the capture layer, carrying a stable machine-readable code.
 *
 * Implements Responsable so the framework renders it without needing a line in
 * `bootstrap/app.php`, matching Modules\Core\Exceptions\MembershipException.
 *
 * Note what is *not* in here: an unrecognised SMS. That is not an error — the
 * message is stored `unparsed` and answered with a 201, because the alternative
 * teaches a forwarding app to retry forever over a bank whose wording changed.
 */
final class CaptureException extends RuntimeException implements Responsable
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

    public static function webhookNotConfigured(): self
    {
        return new self(
            'capture_webhook_not_configured',
            'Inbound mail capture is not configured on this deployment.',
            503,
        );
    }

    public static function webhookUnauthorized(): self
    {
        return new self(
            'capture_webhook_unauthorized',
            'The webhook signature or secret is missing or wrong.',
            401,
        );
    }

    public static function unknownRecipient(string $address): self
    {
        // The token is deliberately not echoed back and a revoked alias gets
        // this same answer: a different one would turn the endpoint into an
        // oracle for which ingest addresses are live.
        return new self(
            'capture_unknown_recipient',
            'No workspace accepts mail at that address.',
            404,
            ['domain' => self::domainOf($address)],
        );
    }

    public static function unrecognisedQr(string $reason): self
    {
        return new self(
            'capture_qr_unrecognised',
            'This QR payload is not in a format that can be read.',
            422,
            ['reason' => $reason],
        );
    }

    public static function qrChecksumMismatch(): self
    {
        return new self(
            'capture_qr_checksum_mismatch',
            'The QR payload failed its own checksum and was misread.',
            422,
        );
    }

    public static function attachmentTooLarge(string $filename, int $bytes, int $limit): self
    {
        return new self(
            'capture_attachment_too_large',
            "Attachment [{$filename}] is larger than this deployment accepts.",
            413,
            ['filename' => $filename, 'bytes' => $bytes, 'limit' => $limit],
        );
    }

    public static function nothingToConfirm(string $id): self
    {
        return new self(
            'capture_nothing_to_confirm',
            "Message [{$id}] produced no draft to confirm.",
            422,
            ['id' => $id],
        );
    }

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'details' => $this->details,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], $this->status);
    }

    private static function domainOf(string $address): string
    {
        $at = strrpos($address, '@');

        return $at === false ? '' : substr($address, $at + 1);
    }
}
