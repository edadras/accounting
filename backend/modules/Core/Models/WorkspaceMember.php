<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Concerns\HasUlidKey;

/**
 * Membership of a user in a workspace, with the role that governs what they
 * may do there.
 */
final class WorkspaceMember extends Model
{
    use HasFactory;
    use HasUlidKey;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_ACCOUNTANT = 'accountant';

    public const ROLE_MEMBER = 'member';

    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_ACCOUNTANT,
        self::ROLE_MEMBER,
        self::ROLE_VIEWER,
    ];

    /** Roles allowed to create or modify financial records. */
    private const CAN_WRITE = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_ACCOUNTANT,
        self::ROLE_MEMBER,
    ];

    /** Roles allowed to edit records other members created. */
    private const CAN_MANAGE_OTHERS = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_ACCOUNTANT,
    ];

    protected $fillable = ['workspace_id', 'user_id', 'role', 'permissions', 'joined_at'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'joined_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function canWrite(): bool
    {
        return in_array($this->role, self::CAN_WRITE, true);
    }

    public function canManageOthers(): bool
    {
        return in_array($this->role, self::CAN_MANAGE_OTHERS, true);
    }

    public function canManageMembers(): bool
    {
        return in_array($this->role, [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }
}
