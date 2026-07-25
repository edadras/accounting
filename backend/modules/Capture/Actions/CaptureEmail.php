<?php

declare(strict_types=1);

namespace Modules\Capture\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\AI\Actions\ParseTransactionText;
use Modules\AI\Actions\StoreDraft;
use Modules\AI\Exceptions\AiException;
use Modules\AI\Jobs\ExtractReceiptJob;
use Modules\AI\Models\AiDraft;
use Modules\AI\Support\AiSwitch;
use Modules\Audit\Support\AuditRecorder;
use Modules\Capture\Exceptions\CaptureException;
use Modules\Capture\Models\CaptureMessage;
use Modules\Capture\Models\IngestAlias;
use Modules\Capture\Support\DedupeKey;
use Modules\Capture\Support\InboxAddress;
use Modules\Capture\Support\MessageRecorder;
use Modules\Core\Models\Workspace;
use Modules\Core\Support\WorkspaceContext;
use Modules\Documents\Models\Document;

/**
 * An inbound email becomes documents and, where there is something to read, a
 * draft.
 *
 * Two things decide safety here and neither is negotiable. The webhook's shared
 * secret is checked before this action is reached, and the recipient address —
 * not anything in the message — decides whose books it lands in. Between them
 * they are the reason a stranger cannot mail a receipt into your ledger.
 *
 * @phpstan-type EmailPayload array{
 *   to: string, from?: string|null, subject?: string|null, text?: string|null,
 *   message_id?: string|null, received_at?: string|null,
 *   attachments?: list<array{filename?: string, content_type?: string|null, content?: string}>
 * }
 */
final readonly class CaptureEmail
{
    public function __construct(
        private WorkspaceContext $context,
        private ParseTransactionText $parse,
        private StoreDraft $store,
        private MessageRecorder $recorder,
        private AuditRecorder $audit,
    ) {}

    /** @param EmailPayload $payload */
    public function handle(array $payload): CaptureMessage
    {
        // The recipient decides whose books this lands in, and it is the one
        // field the webhook always requires; resolve() is what refuses it.
        $to = $payload['to'];
        $routed = InboxAddress::resolve($to);

        if ($routed === null) {
            throw CaptureException::unknownRecipient($to);
        }

        return $this->context->runFor(
            $routed['workspace'],
            fn (): CaptureMessage => $this->ingest($routed['workspace'], $routed['alias'], $to, $payload),
        );
    }

    /** @param EmailPayload $payload */
    private function ingest(Workspace $workspace, IngestAlias $alias, string $to, array $payload): CaptureMessage
    {
        $from = $this->string($payload['from'] ?? null);
        $subject = $this->string($payload['subject'] ?? null);
        $text = $this->string($payload['text'] ?? null);
        $messageId = $this->string($payload['message_id'] ?? null);
        $receivedAt = $this->receivedAt($payload['received_at'] ?? null);

        $attributes = [
            'channel' => CaptureMessage::CHANNEL_EMAIL,
            'sender' => $from,
            'recipient' => $to,
            'subject' => $subject === null ? null : mb_substr($subject, 0, 191),
            'body' => $text,
            // A Message-Id is the provider's own identity for the mail and is
            // stable across the retries that follow a timeout. Without one,
            // the content has to stand in for it.
            'dedupe_key' => DedupeKey::for(
                CaptureMessage::CHANNEL_EMAIL,
                $messageId ?? ((string) $from.'|'.(string) $subject.'|'.(string) $text),
            ),
            'received_at' => $receivedAt,
            'ingest_alias_id' => $alias->id,
        ];

        $earlier = $this->recorder->duplicateOf(CaptureMessage::CHANNEL_EMAIL, $attributes['dedupe_key']);

        if ($earlier !== null) {
            return $this->recorder->storeDuplicate($attributes, $earlier);
        }

        $message = $this->recorder->store(array_merge($attributes, [
            'status' => CaptureMessage::STATUS_UNPARSED,
            'reason' => 'pending',
        ]));

        $receipts = $this->storeAttachments($workspace, $message, $payload['attachments'] ?? []);

        $alias->forceFill(['last_message_at' => $receivedAt])->save();

        $this->audit->record(
            action: 'capture.email_received',
            subject: $message,
            // The body is deliberately absent: the trail records that mail
            // reached these books and from where, not what it said.
            after: ['from' => $from, 'subject' => $subject, 'attachments' => count($receipts['documents'])],
            workspaceId: $workspace->id,
        );

        return $this->resolveOutcome($message, $text ?? $subject, $receipts['queued']);
    }

    /**
     * The body only produces a draft when no receipt was queued from an
     * attachment. Reading both would give one email two drafts for one payment.
     */
    private function resolveOutcome(CaptureMessage $message, ?string $text, int $queued): CaptureMessage
    {
        if ($queued > 0) {
            return $this->finish($message, CaptureMessage::STATUS_PARSED, 'receipt_queued');
        }

        if ($text === null || trim($text) === '') {
            return $this->finish($message, CaptureMessage::STATUS_UNPARSED, 'no_readable_body');
        }

        try {
            $draft = $this->parse->handle($text, ['source' => CaptureMessage::CHANNEL_EMAIL]);
        } catch (AiException $e) {
            // A webhook must not be answered with 5xx because one mail was
            // unreadable — the provider would replay it forever.
            return $this->finish($message, CaptureMessage::STATUS_UNPARSED, $e->errorCode);
        }

        $stored = $this->store->handle($draft, [
            'source' => AiDraft::SOURCE_TEXT,
            'input_text' => $text,
        ]);

        $message->forceFill([
            'status' => CaptureMessage::STATUS_PARSED,
            'reason' => 'body_parsed',
            'parsed' => $draft->toArray(),
            'ai_draft_id' => $stored->id,
        ])->save();

        return $message;
    }

    private function finish(CaptureMessage $message, string $status, string $reason): CaptureMessage
    {
        $message->forceFill(['status' => $status, 'reason' => $reason])->save();

        return $message;
    }

    /**
     * @param  list<array<string, mixed>>  $attachments
     * @return array{documents: list<Document>, queued: int}
     */
    private function storeAttachments(Workspace $workspace, CaptureMessage $message, array $attachments): array
    {
        $disk = (string) config('documents.disk', 'local');
        $limit = (int) config('capture.email.max_attachment_bytes', 10 * 1024 * 1024);
        $max = (int) config('capture.email.max_attachments', 10);
        $receiptMimes = (array) config('capture.email.receipt_mimetypes', []);
        $aiEnabled = AiSwitch::enabledFor($workspace);

        $documents = [];
        $queued = 0;

        foreach (array_slice($attachments, 0, $max) as $attachment) {
            $filename = $this->string($attachment['filename'] ?? null) ?? 'attachment';
            $binary = base64_decode((string) ($attachment['content'] ?? ''), true);

            if ($binary === false || $binary === '') {
                continue;
            }

            if (strlen($binary) > $limit) {
                throw CaptureException::attachmentTooLarge($filename, strlen($binary), $limit);
            }

            $mime = $this->mimeOf($binary, $this->string($attachment['content_type'] ?? null));
            $isReceipt = in_array($mime, $receiptMimes, true);

            $id = (string) Str::ulid();
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $path = "workspaces/{$workspace->id}/documents/".($extension === '' ? $id : "{$id}.{$extension}");

            Storage::disk($disk)->put($path, $binary);

            $document = new Document;
            $document->id = $id;
            $document->fill([
                'disk' => $disk,
                'path' => $path,
                'original_name' => $filename,
                'mime' => $mime,
                'size' => strlen($binary),
                'kind' => $isReceipt ? Document::KIND_RECEIPT : Document::kindForMime($mime),
                'ocr_status' => $isReceipt ? Document::OCR_PENDING : Document::OCR_SKIPPED,
                'checksum' => hash('sha256', $binary),
            ])->save();

            $document->attachTo($message);
            $documents[] = $document;

            if ($isReceipt && $aiEnabled) {
                ExtractReceiptJob::dispatch(
                    $workspace->id,
                    Storage::disk($disk)->path($path),
                    $document->id,
                );

                $queued++;
            }
        }

        return ['documents' => $documents, 'queued' => $queued];
    }

    /**
     * The bytes decide, not the header.
     *
     * A Content-Type in an email is whatever the sender typed, and this
     * decides which files are handed to the OCR pipeline.
     */
    private function mimeOf(string $binary, ?string $declared): ?string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $detected = finfo_buffer($finfo, $binary);
                finfo_close($finfo);

                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return $declared;
    }

    private function receivedAt(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
