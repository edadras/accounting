<?php

declare(strict_types=1);

namespace Modules\Billing\Support;

use Modules\Billing\Exceptions\BillingException;

/**
 * Reads the plan catalogue out of config/plans.php.
 *
 * Deliberately the only reader of that config. Entitlements asks this class
 * what a plan contains; nothing else in the product may.
 */
final class PlanRegistry
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        /** @var array<string, array<string, mixed>> $plans */
        $plans = config('plans.plans', []);

        return $plans;
    }

    public static function defaultCode(): string
    {
        return (string) config('plans.default_plan', 'free');
    }

    public static function trialDays(): int
    {
        return (int) config('plans.trial_days', 14);
    }

    public static function has(string $code): bool
    {
        return array_key_exists($code, self::all());
    }

    /** @return array<string, mixed> */
    public static function get(string $code): array
    {
        return self::all()[$code] ?? throw BillingException::unknownPlan($code);
    }

    /**
     * Where a plan sits in the catalogue order, which is what distinguishes an
     * upgrade from a downgrade.
     */
    public static function rank(string $code): int
    {
        $rank = array_search($code, array_keys(self::all()), true);

        return $rank === false ? throw BillingException::unknownPlan($code) : $rank;
    }

    /** @return array<string, bool> */
    public static function flags(string $code): array
    {
        /** @var array<string, bool> */
        return self::get($code)['flags'] ?? [];
    }

    /** @return array<string, int|null> */
    public static function limits(string $code): array
    {
        /** @var array<string, int|null> */
        return self::get($code)['limits'] ?? [];
    }
}
