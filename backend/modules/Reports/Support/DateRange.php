<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Carbon\CarbonImmutable;

/**
 * The window a report covers.
 *
 * Boundary contract — one rule, applied identically by every report:
 *
 *   • Reports work in whole calendar days. `from` and `to` are truncated to the
 *     start of their day, whatever time of day the caller sent.
 *   • `from` is INCLUSIVE from 00:00:00 of that day.
 *   • `to` is INCLUSIVE of the whole day: the range ends the instant the next
 *     day begins.
 *   • Internally the range is therefore half-open — [start, endExclusive) — so
 *     a transaction at 23:59:59.999 on the `to` day is counted exactly once and
 *     no transaction can fall between two adjacent ranges or into both.
 *
 * A `from` after `to` is not an error. It yields an empty range, and every
 * report answers an empty range with zeroed structures.
 */
final readonly class DateRange
{
    private function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $endExclusive,
    ) {}

    public static function between(
        \DateTimeInterface|string $from,
        \DateTimeInterface|string $to,
    ): self {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay()->addDay();

        return new self($start, $end->max($start));
    }

    /**
     * Builds from optional query input, defaulting to month-to-date — the
     * window the dashboard opens on.
     */
    public static function parse(?string $from, ?string $to, ?CarbonImmutable $today = null): self
    {
        $today ??= CarbonImmutable::now();

        return self::between(
            $from ?? $today->startOfMonth()->format('Y-m-d'),
            $to ?? $today->format('Y-m-d'),
        );
    }

    public function isEmpty(): bool
    {
        return $this->start >= $this->endExclusive;
    }

    /** The last day covered; equals the day before `endExclusive`. */
    public function lastDay(): CarbonImmutable
    {
        return $this->endExclusive->subDay()->startOfDay();
    }

    /** @return array{from:string,to:string} */
    public function toArray(): array
    {
        return [
            'from' => $this->start->format('Y-m-d'),
            'to' => $this->isEmpty()
                ? $this->start->format('Y-m-d')
                : $this->lastDay()->format('Y-m-d'),
        ];
    }
}
