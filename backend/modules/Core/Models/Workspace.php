<?php

declare(strict_types=1);

namespace Modules\Core\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\HasUlidKey;

/**
 * A fully independent set of books: personal life, a company, a building, a
 * trip. Every domain record belongs to exactly one.
 */
final class Workspace extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;
    use SoftDeletes;

    public const TYPES = ['personal', 'business', 'building', 'travel', 'family', 'store'];

    protected $fillable = [
        'owner_id', 'name', 'type', 'base_currency', 'timezone',
        'calendar', 'locale', 'icon', 'color', 'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<WorkspaceMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function memberFor(User $user): ?WorkspaceMember
    {
        return $this->members()->where('user_id', $user->id)->first();
    }

    public function roleFor(User $user): ?string
    {
        return $this->memberFor($user)?->role;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
