<?php

declare(strict_types=1);

namespace Modules\Capture\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AI\Models\AiDraft;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Transaction;

/**
 * One message that arrived from somewhere other than a form.
 *
 * The row exists whatever came of the message. `unparsed` is a first-class
 * outcome, not a failure to record: a bank that changed its wording leaves a
 * trail of messages that can be replayed once a pattern for it is added,
 * whereas a message that was dropped for not matching is simply gone.
 */
final class CaptureMessage extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const CHANNEL_TEXT = 'text';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_QR = 'qr';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNELS = [self::CHANNEL_TEXT, self::CHANNEL_SMS, self::CHANNEL_QR, self::CHANNEL_EMAIL];

    /** A draft was produced, or a document was queued for one. */
    public const STATUS_PARSED = 'parsed';

    /** Kept verbatim for a later pattern; nothing was guessed at. */
    public const STATUS_UNPARSED = 'unparsed';

    /** The same content already produced a draft. */
    public const STATUS_DUPLICATE = 'duplicate';

    /** Refused before parsing — an unusable payload. */
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PARSED, self::STATUS_UNPARSED,
        self::STATUS_DUPLICATE, self::STATUS_REJECTED,
    ];

    /** What `transactions.source` should read once this message is confirmed. */
    public const TRANSACTION_SOURCES = [
        self::CHANNEL_SMS => 'sms',
        self::CHANNEL_EMAIL => 'email',
        self::CHANNEL_QR => 'qr',
        self::CHANNEL_TEXT => 'manual',
    ];

    protected $fillable = [
        'workspace_id', 'channel', 'status', 'sender', 'recipient', 'subject',
        'body', 'dedupe_key', 'duplicate_of_id', 'received_at', 'matched_pattern',
        'parsed', 'reason', 'ingest_alias_id', 'ai_draft_id', 'transaction_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'parsed' => 'array',
            'received_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(AiDraft::class, 'ai_draft_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(IngestAlias::class, 'ingest_alias_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactionSource(): string
    {
        return self::TRANSACTION_SOURCES[$this->channel] ?? 'manual';
    }

    public function scopeOnChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }
}
