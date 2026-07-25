<?php

declare(strict_types=1);

namespace Modules\AI\Support;

use Carbon\CarbonImmutable;

/**
 * Turns the way people say "when" into a date.
 *
 * Deterministic on purpose: "دیروز" is yesterday whatever a model happens to
 * think today is, and the resolver takes `now` as an argument so a frozen
 * clock in a test is the same code path as production.
 *
 * Patterns are tried in order, so the specific ones («اول ماه پیش») come
 * before the general ones («ماه پیش», «اول ماه») and win.
 */
final class RelativeDateResolver
{
    /** @return list<array{0: string, 1: callable(CarbonImmutable, list<string>): ?CarbonImmutable}> */
    private static function patterns(): array
    {
        return [
            ['/اول\s+ماه\s+(?:پیش|گذشته|قبل)/u', fn (CarbonImmutable $n) => $n->subMonthNoOverflow()->startOfMonth()],
            ['/(?:اول|ابتدای|اوایل)\s+(?:این\s+)?ماه/u', fn (CarbonImmutable $n) => $n->startOfMonth()],
            ['/آخر\s+ماه\s+(?:پیش|گذشته|قبل)/u', fn (CarbonImmutable $n) => $n->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            ['/پریروز/u', fn (CarbonImmutable $n) => $n->subDays(2)],
            ['/دیروز|دیشب/u', fn (CarbonImmutable $n) => $n->subDay()],
            ['/امروز|امشب|همین\s+الان/u', fn (CarbonImmutable $n) => $n],
            ['/فردا/u', fn (CarbonImmutable $n) => $n->addDay()],
            ['/(\d+)\s*روز\s*(?:پیش|قبل|گذشته)/u', fn (CarbonImmutable $n, array $m) => $n->subDays((int) $m[1])],
            ['/(\d+)\s*هفته\s*(?:پیش|قبل|گذشته)/u', fn (CarbonImmutable $n, array $m) => $n->subDays(7 * (int) $m[1])],
            ['/(\d+)\s*ماه\s*(?:پیش|قبل|گذشته)/u', fn (CarbonImmutable $n, array $m) => $n->subMonthsNoOverflow((int) $m[1])],
            ['/هفته\s*(?:پیش|قبل|گذشته)/u', fn (CarbonImmutable $n) => $n->subDays(7)],
            ['/ماه\s*(?:پیش|قبل|گذشته)/u', fn (CarbonImmutable $n) => $n->subMonthNoOverflow()],
            ['/سال\s*(?:پیش|قبل|گذشته)|پارسال/u', fn (CarbonImmutable $n) => $n->subYear()],

            ['/the\s+day\s+before\s+yesterday/u', fn (CarbonImmutable $n) => $n->subDays(2)],
            ['/yesterday|last\s+night/u', fn (CarbonImmutable $n) => $n->subDay()],
            ['/today|tonight|this\s+morning|this\s+evening/u', fn (CarbonImmutable $n) => $n],
            ['/tomorrow/u', fn (CarbonImmutable $n) => $n->addDay()],
            ['/(?:last|previous)\s+month/u', fn (CarbonImmutable $n) => $n->subMonthNoOverflow()],
            ['/(?:last|previous)\s+week|a\s+week\s+ago/u', fn (CarbonImmutable $n) => $n->subDays(7)],
            ['/(?:start|beginning|first)\s+of\s+(?:the\s+|this\s+)?month/u', fn (CarbonImmutable $n) => $n->startOfMonth()],
            ['/(\d+)\s*days?\s+ago/u', fn (CarbonImmutable $n, array $m) => $n->subDays((int) $m[1])],
            ['/(\d+)\s*weeks?\s+ago/u', fn (CarbonImmutable $n, array $m) => $n->subDays(7 * (int) $m[1])],
            ['/(\d+)\s*months?\s+ago/u', fn (CarbonImmutable $n, array $m) => $n->subMonthsNoOverflow((int) $m[1])],

            ['/(\d{4})-(\d{2})-(\d{2})/u', fn (CarbonImmutable $n, array $m) => $n
                ->setDate((int) $m[1], (int) $m[2], (int) $m[3])],
        ];
    }

    /** @param string $normalized output of TextNormalizerBridge::forParsing */
    public static function resolve(string $normalized, ?CarbonImmutable $now = null): ?ResolvedDate
    {
        $now = ($now ?? CarbonImmutable::now())->startOfDay();

        foreach (self::patterns() as [$pattern, $resolver]) {
            if (preg_match($pattern, $normalized, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            // No pattern above uses a named group, so the matches are already
            // positional; array_values states that for the resolver's benefit.
            $groups = array_values(array_map(static fn (array $group) => (string) $group[0], $m));
            $date = $resolver($now, $groups);

            if ($date === null) {
                continue;
            }

            return new ResolvedDate(
                $date->startOfDay(),
                (string) $m[0][0],
                (int) $m[0][1],
                strlen((string) $m[0][0]),
            );
        }

        return self::resolveJalaliDayMonth($normalized, $now);
    }

    /**
     * «۵ شهریور» — a day and a Jalali month with no year.
     *
     * The year is inferred as the one that puts the date in the past: someone
     * recording a purchase means the shahrivar that has happened, not the one
     * eleven months away.
     */
    private static function resolveJalaliDayMonth(string $normalized, CarbonImmutable $now): ?ResolvedDate
    {
        $months = implode('|', array_map(
            static fn (string $name) => preg_quote(TextNormalizerBridge::normalize($name), '/'),
            JalaliDate::monthNames(),
        ));

        if (preg_match("/(\d{1,2})\s*(?:م\s+)?({$months})/u", $normalized, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $day = (int) $m[1][0];
        $month = JalaliDate::monthNumber((string) $m[2][0]);

        if ($month === null || $day < 1 || $day > 31) {
            return null;
        }

        [$jy] = JalaliDate::fromGregorian((int) $now->year, (int) $now->month, (int) $now->day);

        [$gy, $gm, $gd] = JalaliDate::toGregorian($jy, $month, $day);
        $date = $now->setDate($gy, $gm, $gd)->startOfDay();

        if ($date->greaterThan($now)) {
            [$gy, $gm, $gd] = JalaliDate::toGregorian($jy - 1, $month, $day);
            $date = $now->setDate($gy, $gm, $gd)->startOfDay();
        }

        return new ResolvedDate($date, (string) $m[0][0], (int) $m[0][1], strlen((string) $m[0][0]));
    }
}
