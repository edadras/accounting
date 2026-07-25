<?php

declare(strict_types=1);

namespace Modules\DataOps\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * One request for a copy of everything in a workspace (docs/07-security.md §8,
 * "the right to take your data out").
 *
 * The row is the promise; the archive it points at is built afterwards, which
 * is why it carries a status rather than only a path.
 */
final class DataExport extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_READY,
        self::STATUS_FAILED,
    ];

    public const FORMAT_ZIP = 'zip';

    protected $fillable = [
        'workspace_id', 'requested_by', 'status', 'format',
        'disk', 'path', 'size', 'error', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_READY
            && $this->path !== null
            && ! $this->hasExpired();
    }

    /** The name the browser saves it under. */
    public function filename(): string
    {
        return "finora-export-{$this->id}.{$this->format}";
    }
}
