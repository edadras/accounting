<?php

declare(strict_types=1);

namespace Modules\DataOps\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Modules\Audit\Support\AuditRecorder;
use Modules\DataOps\Exceptions\DataOpsException;
use Modules\DataOps\Support\AccountDeletion;

/**
 * Marks an account for deletion at the end of the stated grace period
 * (docs/07-security.md §8).
 *
 * The user's tokens are deliberately left alive: cancelling is an authenticated
 * call, and revoking every session here would leave the person who changed
 * their mind with no way back in.
 */
final readonly class ScheduleAccountDeletion
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(User $user, ?int $graceDays = null): CarbonImmutable
    {
        $existing = AccountDeletion::purgeAfter($user);

        if (AccountDeletion::isScheduled($user) && $existing !== null) {
            throw DataOpsException::deletionAlreadyScheduled($existing);
        }

        $graceDays = $graceDays ?? (int) config('dataops.deletion.grace_days');
        $requestedAt = CarbonImmutable::now();
        $purgeAfter = $requestedAt->addDays($graceDays);

        $user->forceFill([
            'deletion_requested_at' => $requestedAt,
            'deletion_purge_after' => $purgeAfter,
        ])->save();

        $this->audit->record(
            action: 'account.deletion_scheduled',
            subject: $user,
            after: [
                'deletion_requested_at' => $requestedAt->toIso8601String(),
                'deletion_purge_after' => $purgeAfter->toIso8601String(),
                'grace_days' => $graceDays,
            ],
        );

        return $purgeAfter;
    }
}
