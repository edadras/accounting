<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Carbon\CarbonImmutable;

/**
 * The time granularity a report is grouped by.
 *
 * Buckets are derived in PHP from a per-day SQL aggregate rather than with
 * DATE_FORMAT/strftime, because week and ISO-year semantics differ between
 * MySQL and SQLite and a report that changes its answer with the driver is
 * worse than a slightly larger result set.
 */
enum Bucket: string
{
    case Day = 'day';

    case Week = 'week';

    case Month = 'month';

    case Year = 'year';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function parse(?string $value, self $default = self::Month): self
    {
        return $value === null ? $default : (self::tryFrom($value) ?? $default);
    }

    public function startOf(CarbonImmutable $moment): CarbonImmutable
    {
        return match ($this) {
            self::Day => $moment->startOfDay(),
            self::Week => $moment->startOfWeek(CarbonImmutable::MONDAY),
            self::Month => $moment->startOfMonth(),
            self::Year => $moment->startOfYear(),
        };
    }

    public function next(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this) {
            self::Day => $start->addDay(),
            self::Week => $start->addWeek(),
            self::Month => $start->addMonth(),
            self::Year => $start->addYear(),
        };
    }

    /** Stable, sortable identifier: 2026-07-25, 2026-W30, 2026-07, 2026. */
    public function keyFor(CarbonImmutable $moment): string
    {
        $start = $this->startOf($moment);

        return match ($this) {
            self::Day => $start->format('Y-m-d'),
            self::Week => $start->format('o-\WW'),
            self::Month => $start->format('Y-m'),
            self::Year => $start->format('Y'),
        };
    }

    /**
     * Every bucket touching the range, in order, clamped to it.
     *
     * A month bucket over 10–20 July still reports "2026-07", but its start and
     * end are the requested days: the numbers only ever cover the range asked
     * for, so a partial period is never presented as a whole one.
     *
     * @return list<Period>
     */
    public function periodsFor(DateRange $range): array
    {
        if ($range->isEmpty()) {
            return [];
        }

        $periods = [];
        $cursor = $this->startOf($range->start);

        while ($cursor < $range->endExclusive) {
            $next = $this->next($cursor);

            $periods[] = new Period(
                key: $this->keyFor($cursor),
                start: $cursor->max($range->start),
                endExclusive: $next->min($range->endExclusive),
            );

            $cursor = $next;
        }

        return $periods;
    }
}
