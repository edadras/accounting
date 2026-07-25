<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Payroll\Exceptions\PayrollException;

/**
 * The stretch of days a payroll run pays for.
 *
 * Days are counted inclusively at both ends, because that is how a person
 * reads "1 March to 31 March", and a payslip that pays for 30 days of a 31 day
 * month is the kind of error nobody notices until the twelfth month.
 */
final readonly class PayPeriod
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(DateTimeInterface|string $start, DateTimeInterface|string $end)
    {
        $this->start = CarbonImmutable::parse($start)->startOfDay();
        $this->end = CarbonImmutable::parse($end)->startOfDay();

        if ($this->end->lessThan($this->start)) {
            throw PayrollException::periodEndsBeforeItStarts(
                $this->start->toDateString(),
                $this->end->toDateString(),
            );
        }
    }

    public static function ofMonth(int $year, int $month): self
    {
        $start = CarbonImmutable::create($year, $month, 1)?->startOfDay() ?? CarbonImmutable::now()->startOfMonth();

        return new self($start, $start->endOfMonth()->startOfDay());
    }

    /** Inclusive day count: 1–31 March is 31, not 30. */
    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** 1–12, taken from the day the period opens. */
    public function monthIndex(): int
    {
        return (int) $this->start->format('n');
    }

    /**
     * How many days of this period fall inside an employment that runs from
     * $from to $to. An employment that ended before the period opens scores
     * zero, which is what keeps a leaver off a later run's arithmetic even if
     * they somehow reach it.
     */
    public function overlapDays(?DateTimeInterface $from, ?DateTimeInterface $to): int
    {
        $begins = $from === null ? $this->start : CarbonImmutable::parse($from)->startOfDay()->max($this->start);
        $ends = $to === null ? $this->end : CarbonImmutable::parse($to)->startOfDay()->min($this->end);

        if ($ends->lessThan($begins)) {
            return 0;
        }

        return (int) $begins->diffInDays($ends) + 1;
    }

    public function reference(string $format, string $prefix): string
    {
        return str_replace(
            ['{prefix}', '{year}', '{month}'],
            [$prefix, $this->start->format('Y'), $this->start->format('m')],
            $format,
        );
    }
}
