<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The words an exported file is allowed to contain.
 *
 * docs/06-i18n-rtl.md §2: display strings live in the translations table, not
 * in code. They are read from there first, so a wording change reaches an
 * export without a release.
 *
 * The built-in map is a fallback, not a second source of truth. It covers `fa`
 * and `en` — the two locales the doc makes blocking — because a header row is
 * the one part of an export that cannot be missing: a CSV whose first line is
 * empty is not a smaller file, it is an unreadable one. `tr` and `ar` are not
 * blocking, so they fall back to English until the translations are supplied.
 */
final class ExportLabels
{
    public const GROUP = 'report';

    /** @var array<string, array<string, string>> */
    private const FALLBACK = [
        'title.cash-flow' => ['en' => 'Cash flow', 'fa' => 'جریان نقدی'],
        'title.net-worth' => ['en' => 'Net worth', 'fa' => 'ارزش خالص دارایی'],
        'title.expense-trend' => ['en' => 'Expense trend', 'fa' => 'روند هزینه'],
        'title.income-trend' => ['en' => 'Income trend', 'fa' => 'روند درآمد'],
        'title.top-categories' => ['en' => 'Top categories', 'fa' => 'دسته‌های برتر'],
        'title.top-merchants' => ['en' => 'Top merchants', 'fa' => 'فروشندگان برتر'],
        'title.top-accounts' => ['en' => 'Top accounts', 'fa' => 'حساب‌های برتر'],
        'title.category-breakdown' => ['en' => 'Category breakdown', 'fa' => 'تفکیک دسته‌ها'],

        'column.period' => ['en' => 'Period', 'fa' => 'دوره'],
        'column.start' => ['en' => 'From', 'fa' => 'از'],
        'column.end' => ['en' => 'To', 'fa' => 'تا'],
        'column.income' => ['en' => 'Income', 'fa' => 'درآمد'],
        'column.expense' => ['en' => 'Expense', 'fa' => 'هزینه'],
        'column.net' => ['en' => 'Net', 'fa' => 'خالص'],
        'column.total' => ['en' => 'Total', 'fa' => 'جمع'],
        'column.net_worth' => ['en' => 'Net worth', 'fa' => 'ارزش خالص'],
        'column.transactions' => ['en' => 'Transactions', 'fa' => 'تعداد تراکنش'],
        'column.rank' => ['en' => 'Rank', 'fa' => 'رتبه'],
        'column.category' => ['en' => 'Category', 'fa' => 'دسته'],
        'column.path' => ['en' => 'Path', 'fa' => 'مسیر'],
        'column.merchant' => ['en' => 'Merchant', 'fa' => 'فروشنده'],
        'column.account' => ['en' => 'Account', 'fa' => 'حساب'],
        'column.account_type' => ['en' => 'Type', 'fa' => 'نوع'],
        'column.currency' => ['en' => 'Currency', 'fa' => 'ارز'],
        'column.share' => ['en' => 'Share %', 'fa' => 'سهم ٪'],

        'meta.range' => ['en' => 'Period covered', 'fa' => 'بازهٔ گزارش'],
        'meta.currency' => ['en' => 'Currency', 'fa' => 'ارز'],
        'meta.bucket' => ['en' => 'Grouped by', 'fa' => 'گروه‌بندی'],
        'meta.generated_at' => ['en' => 'Generated', 'fa' => 'زمان تهیه'],

        'summary.income' => ['en' => 'Total income', 'fa' => 'جمع درآمد'],
        'summary.expense' => ['en' => 'Total expense', 'fa' => 'جمع هزینه'],
        'summary.net' => ['en' => 'Net', 'fa' => 'خالص'],
        'summary.total' => ['en' => 'Total', 'fa' => 'جمع کل'],
        'summary.transactions' => ['en' => 'Transactions', 'fa' => 'تعداد تراکنش'],
        'summary.average_per_period' => ['en' => 'Average per period', 'fa' => 'میانگین هر دوره'],
        'summary.other' => ['en' => 'Other', 'fa' => 'سایر'],
        'summary.unlabelled' => ['en' => 'Unlabelled', 'fa' => 'بدون برچسب'],
        'summary.no_rows' => ['en' => 'No data in this period', 'fa' => 'داده‌ای در این بازه نیست'],

        'bucket.day' => ['en' => 'Day', 'fa' => 'روز'],
        'bucket.week' => ['en' => 'Week', 'fa' => 'هفته'],
        'bucket.month' => ['en' => 'Month', 'fa' => 'ماه'],
        'bucket.year' => ['en' => 'Year', 'fa' => 'سال'],
    ];

    /** @var array<string, array<string, string>> */
    private static array $overrides = [];

    /** @var list<string> */
    private static array $loaded = [];

    public static function get(string $key, ExportLocale $locale): string
    {
        self::load($locale);

        return self::$overrides[$locale->value][$key]
            ?? self::FALLBACK[$key][$locale->value]
            ?? self::FALLBACK[$key]['en']
            ?? $key;
    }

    /** Test seam: drops the per-locale cache so a fresh read hits the table. */
    public static function flush(): void
    {
        self::$overrides = [];
        self::$loaded = [];
    }

    private static function load(ExportLocale $locale): void
    {
        if (in_array($locale->value, self::$loaded, true)) {
            return;
        }

        self::$loaded[] = $locale->value;
        self::$overrides[$locale->value] = [];

        // The table is Core's, and an export must still work in a context that
        // has not migrated it — a console command, say, or a fresh install.
        if (! Schema::hasTable('translations')) {
            return;
        }

        self::$overrides[$locale->value] = DB::table('translations')
            ->where('locale', $locale->value)
            ->where('group', self::GROUP)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->pluck('value', 'key')
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();
    }
}
