<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Carbon\CarbonImmutable;

/** One bucket of a report's time axis, half-open: [start, endExclusive). */
final readonly class Period
{
    public function __construct(
        public string $key,
        public CarbonImmutable $start,
        public CarbonImmutable $endExclusive,
    ) {}

    /** The last day the period actually covers, for display. */
    public function lastDay(): CarbonImmutable
    {
        return $this->endExclusive->subDay()->startOfDay();
    }

    public function contains(CarbonImmutable $day): bool
    {
        return $day >= $this->start && $day < $this->endExclusive;
    }

    /** @return array{key:string,start:string,end:string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'start' => $this->start->format('Y-m-d'),
            'end' => $this->lastDay()->format('Y-m-d'),
        ];
    }
}
