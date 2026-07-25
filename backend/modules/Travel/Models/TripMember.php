<?php

declare(strict_types=1);

namespace Modules\Travel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

/**
 * Somebody on the trip. `user_id` is null when the member is a plain contact
 * who does not have an account.
 */
final class TripMember extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = ['trip_id', 'workspace_id', 'user_id', 'display_name', 'weight'];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SplitShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(SplitShare::class, 'member_id');
    }

    /**
     * @return HasMany<SplitExpense, $this>
     */
    public function expensesPaid(): HasMany
    {
        return $this->hasMany(SplitExpense::class, 'payer_member_id');
    }
}
