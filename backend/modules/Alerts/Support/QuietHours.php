<?php

declare(strict_types=1);

namespace Modules\Alerts\Support;

use Carbon\CarbonImmutable;
use Modules\Alerts\Exceptions\AlertException;

/**
 * A member's "do not disturb" window, as wall-clock times in their own zone.
 *
 * An alert that lands inside the window is moved to the moment it closes. It is
 * never dropped: the cheque is still due tomorrow whether or not the reminder
 * happened to be computed at 3am.
 */
final readonly class QuietHours
{
    private function __construct(
        private ?int $startMinutes,
        private ?int $endMinutes,
        private string $timezone,
    ) {}

    public static function none(): self
    {
        return new self(null, null, 'UTC');
    }

    public static function between(?string $start, ?string $end, ?string $timezone = null): self
    {
        if ($start === null || $end === null) {
            return self::none();
        }

        return new self(
            self::minutes($start),
            self::minutes($end),
            $timezone ?? 'UTC',
        );
    }

    public function isEmpty(): bool
    {
        // Equal ends would describe a zero-length window, which no user means;
        // treating it as "no quiet hours" beats deferring forever.
        return $this->startMinutes === null
            || $this->endMinutes === null
            || $this->startMinutes === $this->endMinutes;
    }

    public function covers(\DateTimeInterface $at): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        $local = CarbonImmutable::instance($at)->setTimezone($this->timezone);
        $minute = ($local->hour * 60) + $local->minute;

        return $this->startMinutes < $this->endMinutes
            ? $minute >= $this->startMinutes && $minute < $this->endMinutes
            // A window that wraps midnight, e.g. 22:00 to 07:00.
            : $minute >= $this->startMinutes || $minute < $this->endMinutes;
    }

    /** The moment itself when it is allowed, otherwise the end of the window it fell in. */
    public function nextAllowed(\DateTimeInterface $at): CarbonImmutable
    {
        $moment = CarbonImmutable::instance($at);

        // The endMinutes check is what covers() already implies through
        // isEmpty(); stating it here keeps the window's closing time provably
        // a number rather than a maybe-null.
        if (! $this->covers($moment) || $this->endMinutes === null) {
            return $moment->utc();
        }

        $local = $moment->setTimezone($this->timezone);
        $end = $local->startOfDay()
            ->addMinutes($this->endMinutes)
            ->setSecond(0);

        // Already past today's closing time — we are in the evening half of a
        // window that wraps midnight, so it closes tomorrow morning.
        if ($end->lessThanOrEqualTo($local)) {
            $end = $end->addDay();
        }

        return $end->utc();
    }

    private static function minutes(string $time): int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $parts) !== 1) {
            throw AlertException::invalidQuietWindow($time);
        }

        return ((int) $parts[1] * 60) + (int) $parts[2];
    }
}
