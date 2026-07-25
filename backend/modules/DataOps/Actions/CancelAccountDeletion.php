<?php

declare(strict_types=1);

namespace Modules\DataOps\Actions;

use App\Models\User;
use Modules\Audit\Support\AuditRecorder;
use Modules\DataOps\Exceptions\DataOpsException;
use Modules\DataOps\Support\AccountDeletion;

/**
 * Takes an account back off the purge list, if the grace period still has time
 * left on it.
 */
final readonly class CancelAccountDeletion
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(User $user): User
    {
        $purgeAfter = AccountDeletion::purgeAfter($user);

        if (! AccountDeletion::isScheduled($user) || $purgeAfter === null) {
            throw DataOpsException::deletionNotScheduled();
        }

        if (! $purgeAfter->isFuture()) {
            // The window has run out. The rows may still be here only because
            // `accounts:purge` has not run yet, and saying "restored" about an
            // account that is about to disappear would be a lie.
            throw DataOpsException::deletionWindowClosed($purgeAfter);
        }

        $user->forceFill([
            'deletion_requested_at' => null,
            'deletion_purge_after' => null,
        ])->save();

        $this->audit->record(
            action: 'account.deletion_cancelled',
            subject: $user,
            before: ['deletion_purge_after' => $purgeAfter->toIso8601String()],
        );

        return $user;
    }
}
