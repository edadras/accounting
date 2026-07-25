<?php

declare(strict_types=1);

namespace Modules\Sync\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Concerns\HasUlidKey;

/**
 * An installation of the app.
 *
 * A device belongs to a user, not to a workspace: the same phone syncs the
 * personal books, the company's and the building's, and revoking it has to stop
 * all three at once.
 */
final class Device extends Model
{
    use HasUlidKey;

    public const PLATFORMS = ['ios', 'android', 'web', 'desktop', 'unknown'];

    protected $fillable = [
        'id', 'user_id', 'platform', 'name', 'push_token', 'last_seen_at', 'revoked_at',
    ];

    protected $hidden = ['push_token'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * @return HasMany<SyncChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(SyncChange::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function markSeen(): void
    {
        $this->forceFill(['last_seen_at' => now()])->saveQuietly();
    }
}
