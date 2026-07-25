<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

/**
 * The locale an export is rendered for (docs/06-i18n-rtl.md §1).
 *
 * Direction and calendar are properties of the locale, not of the exporter, so
 * they are answered here once and every format asks the same question.
 */
enum ExportLocale: string
{
    case Fa = 'fa';

    case En = 'en';

    case Tr = 'tr';

    case Ar = 'ar';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public static function parse(?string $value, self $default = self::Fa): self
    {
        return $value === null ? $default : (self::tryFrom($value) ?? $default);
    }

    public function isRtl(): bool
    {
        return $this === self::Fa || $this === self::Ar;
    }

    /**
     * The ICU locale used to format dates.
     *
     * Persian defaults to the Jalali calendar, which is what the app shows, and
     * every locale is pinned to Latin digits: an exported file is data before
     * it is a document, and a spreadsheet cannot parse ۱۴۰۴.
     */
    public function intlLocale(): string
    {
        return match ($this) {
            self::Fa => 'fa_IR@calendar=persian;numbers=latn',
            self::En => 'en_US@numbers=latn',
            self::Tr => 'tr_TR@numbers=latn',
            self::Ar => 'ar@numbers=latn',
        };
    }

    public function usesJalaliCalendar(): bool
    {
        return $this === self::Fa;
    }
}
