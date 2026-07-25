<?php

declare(strict_types=1);

namespace Modules\Family\Models;

use App\Core\Money\Money;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;
use Modules\Ledger\Models\Account;

/**
 * One person in the household: a parent who pays, a child who is given an
 * allowance and a ceiling, or somebody who is simply counted.
 */
final class FamilyMember extends Model
{
    use BelongsToWorkspace;
    use HasFactory;
    use HasUlidKey;

    public const ROLE_PARENT = 'parent';

    public const ROLE_CHILD = 'child';

    public const ROLE_OTHER = 'other';

    public const ROLES = [self::ROLE_PARENT, self::ROLE_CHILD, self::ROLE_OTHER];

    /**
     * Prefix of the transaction tag that attributes spending to a member.
     *
     * A tag rather than a foreign key on `transactions`: the Ledger module owns
     * that table and must not grow a column for every module that wants to
     * point at a transaction. Business does the same for projects.
     */
    public const TAG_PREFIX = 'member:';

    protected $fillable = [
        'workspace_id', 'user_id', 'display_name', 'role', 'birth_date',
        'monthly_allowance', 'currency', 'spending_cap', 'account_id',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'monthly_allowance' => 'integer',
            'spending_cap' => 'integer',
        ];
    }

    public static function tagFor(string $memberId): string
    {
        return self::TAG_PREFIX.$memberId;
    }

    public function tag(): string
    {
        return self::tagFor($this->id);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function allowancePayments(): HasMany
    {
        return $this->hasMany(AllowancePayment::class, 'member_id');
    }

    public function isChild(): bool
    {
        return $this->role === self::ROLE_CHILD;
    }

    public function allowance(): ?Money
    {
        return $this->monthly_allowance === null
            ? null
            : Money::of($this->monthly_allowance, $this->currency);
    }

    public function spendingCap(): ?Money
    {
        return $this->spending_cap === null
            ? null
            : Money::of($this->spending_cap, $this->currency);
    }

    public function scopeOfRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }
}
