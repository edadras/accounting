<?php

declare(strict_types=1);

namespace Modules\Sync\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * The server's log of one pushed change and the verdict it received.
 *
 * It exists so a batch can be replayed safely: a connection dropped mid-push is
 * the normal case, not an edge case, and the device has no way to know whether
 * the server got the batch before the socket died. On a replay the verdict is
 * read back from here instead of being decided a second time.
 */
final class SyncChange extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const OP_CREATE = 'create';

    public const OP_UPDATE = 'update';

    public const OP_DELETE = 'delete';

    public const OPERATIONS = [self::OP_CREATE, self::OP_UPDATE, self::OP_DELETE];

    public const APPLIED = 'applied';

    /** Non-financial fields reconciled automatically; nothing for the user to do. */
    public const MERGED = 'merged';

    /** Held for the user to decide — never resolved by guessing. */
    public const CONFLICT = 'conflict';

    /** The change could not be applied at all: bad payload, missing account. */
    public const REJECTED = 'rejected';

    public const STATUSES = [self::APPLIED, self::MERGED, self::CONFLICT, self::REJECTED];

    protected $fillable = [
        'workspace_id', 'device_id', 'entity_type', 'entity_id', 'operation',
        'payload', 'client_version', 'server_version', 'applied_at', 'status',
        'conflict_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'client_version' => 'integer',
            'server_version' => 'integer',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function isUnresolved(): bool
    {
        return $this->status === self::CONFLICT;
    }
}
