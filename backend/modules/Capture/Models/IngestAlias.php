<?php

declare(strict_types=1);

namespace Modules\Capture\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Audit\Concerns\Auditable;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * The mail address that routes into one workspace's books.
 *
 * The token is shown once, at creation, and only its SHA-256 is kept. Anyone
 * who knows the address can post into these books, so it is treated as the
 * bearer credential it is: a dump of this table must not yield a working inbox
 * address for every workspace in the system.
 *
 * Lookup is by unsalted hash on purpose — a per-row salt would make finding the
 * alias for an incoming address impossible without scanning every row. The
 * token's own 128 bits of entropy are what make it unguessable, not the hash.
 */
final class IngestAlias extends Model
{
    use Auditable;
    use BelongsToWorkspace;
    use HasUlidKey;

    protected $table = 'capture_ingest_aliases';

    protected $fillable = [
        'workspace_id', 'token_hash', 'domain', 'label',
        'last_message_at', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', mb_strtolower(trim($token)));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
