<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * Jalali ↔ Gregorian, so «۵ شهریور» can become a date.
 *
 * Pure integer arithmetic on the standard 33-year cycle — no extension, no
 * package, and no drift against the Iranian civil calendar in the range this
 * product cares about.
 */
final class JalaliDate
{
    /** @var list<string> */
    private const MONTHS = [
        'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور',
        'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند',
    ];

    /**
     * Spelling variants that reach us from keyboards and transcription.
     *
     * @var array<string, int>
     */
    private const ALIASES = [
        'امرداد' => 5,
        'اسپند' => 12,
    ];

    /** 1-based month number, or null when the word is not a Jalali month. */
    public static function monthNumber(string $word): ?int
    {
        $word = TextNormalizerBridge::normalize($word);

        foreach (self::MONTHS as $index => $name) {
            if (TextNormalizerBridge::normalize($name) === $word) {
                return $index + 1;
            }
        }

        foreach (self::ALIASES as $alias => $number) {
            if (TextNormalizerBridge::normalize($alias) === $word) {
                return $number;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function monthNames(): array
    {
        return self::MONTHS;
    }

    /** @return array{int, int, int} [year, month, day] */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $days--;
            $gy += 100 * intdiv($days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $lengths = [0, 31, self::isGregorianLeap($gy) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 0;

        while ($gm < 13 && $gd > $lengths[$gm]) {
            $gd -= $lengths[$gm];
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /** @return array{int, int, int} [year, month, day] */
    public static function fromGregorian(int $gy, int $gm, int $gd): array
    {
        $cumulative = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $reference = $gm > 2 ? $gy + 1 : $gy;

        $days = 355666 + (365 * $gy) + intdiv($reference + 3, 4) - intdiv($reference + 99, 100)
            + intdiv($reference + 399, 400) + $gd + $cumulative[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        return $days < 186
            ? [$jy, 1 + intdiv($days, 31), 1 + ($days % 31)]
            : [$jy, 7 + intdiv($days - 186, 30), 1 + (($days - 186) % 30)];
    }

    private static function isGregorianLeap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }
}
