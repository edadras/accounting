<?php

declare(strict_types=1);

namespace Modules\Payroll\Support;

use App\Core\Money\Money;
use Modules\Payroll\Exceptions\PayrollException;

/**
 * Turns a contractual rate into the amount owed for one pay period.
 *
 * Everything here is integer allocation rather than multiplication by a
 * fraction. `allocateEvenly` and `allocateByWeights` guarantee that the slices
 * add back up to the amount they came from, so a twelfth of an annual salary
 * paid twelve times is the annual salary exactly, and a half-month worked plus
 * the other half is a whole month — not a whole month minus a stray unit.
 */
final readonly class CompensationProrator
{
    public const PERIOD_MONTHLY = 'monthly';

    public const PERIOD_ANNUAL = 'annual';

    public const PERIOD_WEEKLY = 'weekly';

    public const PERIOD_DAILY = 'daily';

    public const PERIODS = [
        self::PERIOD_MONTHLY,
        self::PERIOD_ANNUAL,
        self::PERIOD_WEEKLY,
        self::PERIOD_DAILY,
    ];

    public function handle(Money $rate, string $period, PayPeriod $payPeriod, int $workedDays): Money
    {
        if (! in_array($period, self::PERIODS, true)) {
            throw PayrollException::unknownPayPeriod($period);
        }

        $periodDays = $payPeriod->days();

        $full = match ($period) {
            self::PERIOD_MONTHLY => $rate,

            // The month's twelfth, not "annual ÷ 12 rounded": the twelve
            // slices of a year add up to the year.
            self::PERIOD_ANNUAL => $rate->allocateEvenly(12)[$payPeriod->monthIndex() - 1],

            self::PERIOD_WEEKLY => $this->weeks($rate, $periodDays),
            self::PERIOD_DAILY => Money::of($rate->minorUnits * $periodDays, $rate->currency),
        };

        if ($workedDays >= $periodDays) {
            return $full;
        }

        if ($workedDays <= 0) {
            return Money::zero($rate->currency);
        }

        // Two weights that sum to the period: the worked share and the rest.
        // Taking the first slice means the two shares of a split month always
        // reconstitute the month.
        return $full->allocateByWeights([$workedDays, $periodDays - $workedDays])[0];
    }

    /** Whole weeks paid in full, the trailing days as day-slices of one week. */
    private function weeks(Money $rate, int $periodDays): Money
    {
        $total = Money::of($rate->minorUnits * intdiv($periodDays, 7), $rate->currency);
        $remainder = $periodDays % 7;

        if ($remainder === 0) {
            return $total;
        }

        $days = $rate->allocateEvenly(7);

        for ($day = 0; $day < $remainder; $day++) {
            $total = $total->plus($days[$day]);
        }

        return $total;
    }
}
