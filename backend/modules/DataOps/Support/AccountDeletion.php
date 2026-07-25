<?php

declare(strict_types=1);

namespace Modules\DataOps\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Reads the two deletion columns off a user.
 *
 * They are added to `users` by this module's migration, and App\Models\User —
 * which belongs to the application, not to DataOps — has no cast for them, so
 * the raw attribute may arrive as a string or as a date object depending on
 * whether the row has been round-tripped. Both are parsed here, once.
 */
final class AccountDeletion
{
    public static function requestedAt(User $user): ?CarbonImmutable
    {
        return self::parse($user->getAttribute('deletion_requested_at'));
    }

    public static function purgeAfter(User $user): ?CarbonImmutable
    {
        return self::parse($user->getAttribute('deletion_purge_after'));
    }

    public static function isScheduled(User $user): bool
    {
        return self::requestedAt($user) !== null;
    }

    /** True once the grace period has run out and the purge may go ahead. */
    public static function isDue(User $user): bool
    {
        $purgeAfter = self::purgeAfter($user);

        return $purgeAfter !== null && ! $purgeAfter->isFuture();
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        return match (true) {
            $value === null, $value === '' => null,
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            default => CarbonImmutable::parse((string) $value),
        };
    }
}
