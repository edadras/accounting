<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Carbon\CarbonImmutable;
use IntlDateFormatter;

/**
 * Renders a stored date the way the app shows it in the requested locale.
 *
 * Dates are stored and reported as Gregorian ISO-8601 (docs/06-i18n-rtl.md §4);
 * the calendar is a display choice, applied here and nowhere near the database.
 * A Persian export therefore reads 1404-12-16, the same day the user saw on the
 * screen — an export that silently switched calendars would be a wrong date.
 */
final class LocalizedDate
{
    /** @var array<string, IntlDateFormatter> */
    private static array $formatters = [];

    /** ISO input ("2026-03-07") to the locale's calendar, still yyyy-MM-dd. */
    public static function iso(string $date, ExportLocale $locale): string
    {
        return self::format($date, $locale, 'yyyy-MM-dd');
    }

    /** A human date for a PDF cover line: "16 اسفند 1404", "7 March 2026". */
    public static function long(string $date, ExportLocale $locale): string
    {
        return self::format($date, $locale, 'd MMMM yyyy');
    }

    private static function format(string $date, ExportLocale $locale, string $pattern): string
    {
        // Pinned to UTC on both sides: a report day is a calendar day, and
        // reading it in a westward timezone would show the day before.
        $moment = CarbonImmutable::parse($date, 'UTC')->startOfDay();

        $key = $locale->value.'|'.$pattern;

        self::$formatters[$key] ??= new IntlDateFormatter(
            $locale->intlLocale(),
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            'UTC',
            $locale->usesJalaliCalendar()
                ? IntlDateFormatter::TRADITIONAL
                : IntlDateFormatter::GREGORIAN,
            $pattern,
        );

        $formatted = self::$formatters[$key]->format($moment->toDateTimeImmutable());

        // ICU can fail on an out-of-calendar-range date. The ISO form is always
        // truthful, so falling back to it beats emitting `false`.
        return $formatted === false ? $moment->format('Y-m-d') : $formatted;
    }
}
