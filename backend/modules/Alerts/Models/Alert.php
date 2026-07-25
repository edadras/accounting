<?php

declare(strict_types=1);

namespace Modules\Alerts\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

final class Alert extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED];

    public const DELIVERY_PENDING = 'pending';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    protected $fillable = [
        'workspace_id', 'user_id', 'type', 'payload', 'scheduled_at',
        'sent_at', 'read_at', 'channels', 'status', 'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'channels' => 'array',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDue(Builder $query, \DateTimeInterface $at): Builder
    {
        return $query->where('status', self::STATUS_PENDING)->where('scheduled_at', '<=', $at);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isDeferred(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->scheduled_at->isFuture() === true;
    }

    /**
     * Per-channel delivery status, keyed by channel.
     *
     * The column is a JSON object written by the deliverer, so the values are
     * normalised here rather than trusted to already be strings.
     *
     * @return array<string, string>
     */
    public function deliveries(): array
    {
        $deliveries = [];

        foreach ($this->channels as $channel => $status) {
            $deliveries[(string) $channel] = (string) $status;
        }

        return $deliveries;
    }

    public function deliveryStatus(string $channel): ?string
    {
        return $this->deliveries()[$channel] ?? null;
    }

    public function wasDeliveredOn(string $channel): bool
    {
        return $this->deliveryStatus($channel) === self::DELIVERY_SENT;
    }
}
