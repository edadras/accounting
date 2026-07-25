<?php

declare(strict_types=1);

namespace Modules\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Billing\Support\PlanRegistry;
use Modules\Core\Concerns\BelongsToWorkspace;
use Modules\Core\Concerns\HasUlidKey;

final class Subscription extends Model
{
    use BelongsToWorkspace;
    use HasUlidKey;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_TRIALING,
        self::STATUS_ACTIVE,
        self::STATUS_PAST_DUE,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'workspace_id', 'plan_code', 'status', 'trial_ends_at', 'renews_at',
        'cancelled_at', 'gateway', 'gateway_reference',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'renews_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** Bypasses the workspace scope so entitlements can be resolved for any workspace. */
    public static function forWorkspaceId(string $workspaceId): ?self
    {
        return self::query()->withoutWorkspaceScope()->where('workspace_id', $workspaceId)->first();
    }

    /**
     * The plan actually in force right now.
     *
     * A trial that has run out, a cancellation whose paid period has ended and
     * a renewal that never happened all resolve to the default plan. Storing
     * that as a status change would depend on a job having run; deriving it
     * from the clock means an expired subscription cannot keep handing out paid
     * features because a cron was down.
     */
    public function effectivePlanCode(): string
    {
        $fallback = PlanRegistry::defaultCode();
        $now = CarbonImmutable::now();

        return match ($this->status) {
            self::STATUS_TRIALING => $this->trial_ends_at?->greaterThan($now) === true
                ? $this->plan_code
                : $fallback,

            self::STATUS_ACTIVE, self::STATUS_PAST_DUE => $this->renews_at === null
                || $this->renews_at->greaterThan($now)
                    ? $this->plan_code
                    : $fallback,

            // Cancelled keeps what was paid for until the period runs out.
            self::STATUS_CANCELLED => $this->renews_at?->greaterThan($now) === true
                ? $this->plan_code
                : $fallback,

            default => $fallback,
        };
    }

    public function isOnTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING
            && $this->trial_ends_at?->isFuture() === true;
    }

    public function hasUsedTrial(): bool
    {
        return $this->trial_ends_at !== null;
    }
}
