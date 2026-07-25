<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Transaction;

/**
 * A stored suggestion awaiting a human.
 *
 * `transaction_id` stays null until someone confirms it, which makes "what did
 * the AI write into my books without asking" a query with a guaranteed empty
 * answer.
 */
final class AiDraft extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const KIND_TRANSACTION = 'transaction';

    public const KIND_RECEIPT = 'receipt';

    public const KINDS = [self::KIND_TRANSACTION, self::KIND_RECEIPT];

    public const SOURCE_TEXT = 'text';

    public const SOURCE_VOICE = 'voice';

    public const SOURCE_OCR = 'ocr';

    public const SOURCES = [self::SOURCE_TEXT, self::SOURCE_VOICE, self::SOURCE_OCR];

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'workspace_id', 'kind', 'source', 'status', 'input_text', 'payload',
        'warnings', 'confidence', 'needs_confirmation', 'document_id',
        'transaction_id', 'created_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'warnings' => 'array',
            'confidence' => 'float',
            'needs_confirmation' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
