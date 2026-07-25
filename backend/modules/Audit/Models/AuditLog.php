<?php

declare(strict_types=1);

namespace Modules\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Core\Models\Workspace;

/**
 * One entry in the trail. Append-only, by construction.
 *
 * The model is deliberately NOT workspace-scoped through the global scope:
 * sign-in rows carry no workspace, and a scope that fails closed would make
 * them permanently unreachable. Reads are constrained explicitly instead —
 * `forWorkspace()` for a workspace trail, `forUser()` for a personal one — and
 * both controllers use them.
 */
final class AuditLog extends Model
{
    use HasUlidKey;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id', 'user_id', 'action', 'subject_type', 'subject_id',
        'before', 'after', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new LogicException('Audit entries are append-only and cannot be updated.');
        });

        self::deleting(function (): never {
            throw new LogicException('Audit entries are append-only and cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int|string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
