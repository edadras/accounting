<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

use App\Core\Money\Money;

/**
 * Turns a report payload into the flat table an exporter writes.
 *
 * Dispatch is a `match` over the same whitelist the queries use, so an export
 * cannot be asked to flatten something that is not a report.
 *
 * One deliberate omission: the `period` column is not translated into the
 * locale's calendar. It is an identity — "2026-03", the key the report groups
 * by — and a Gregorian month is not a Jalali month, so relabelling it 1404-12
 * would name a period the numbers do not actually cover. The `from`/`to`
 * columns beside it are real calendar days and are shown in the locale's own
 * calendar (Bucket documents the same reasoning for how keys are built).
 */
final class ReportTableFactory
{
    /** @param  array<string, mixed>  $data */
    public function make(string $type, array $data, ExportLocale $locale): ReportTable
    {
        [$columns, $rows, $summary] = match ($type) {
            'cash-flow' => $this->cashFlow($data, $locale),
            'net-worth' => $this->netWorth($data, $locale),
            'expense-trend', 'income-trend' => $this->trend($data, $locale),
            'top-categories' => $this->topCategories($data, $locale),
            'top-merchants' => $this->topMerchants($data, $locale),
            'top-accounts' => $this->topAccounts($data, $locale),
            'category-breakdown' => $this->categoryBreakdown($data, $locale),
            default => throw new \InvalidArgumentException("Unknown report type [{$type}]."),
        };

        return new ReportTable(
            type: $type,
            title: ExportLabels::get("title.{$type}", $locale),
            columns: $columns,
            rows: $rows,
            summary: $summary,
            meta: $this->meta($data, $locale),
            locale: $locale,
            emptyLabel: ExportLabels::get('summary.no_rows', $locale),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:list<Column>,1:list<array<string,mixed>>,2:list<array{label:string,value:string}>}
     */
    private function cashFlow(array $data, ExportLocale $locale): array
    {
        $columns = [
            ...$this->periodColumns($locale),
            new Column('income', ExportLabels::get('column.income', $locale), Column::MONEY),
            new Column('expense', ExportLabels::get('column.expense', $locale), Column::MONEY),
            new Column('net', ExportLabels::get('column.net', $locale), Column::MONEY),
            new Column('transactions', ExportLabels::get('column.transactions', $locale), Column::COUNT),
        ];

        $rows = [];

        foreach ($data['periods'] ?? [] as $period) {
            $rows[] = [
                ...$this->periodCells($period, $locale),
                'income' => $this->money($period['income']),
                'expense' => $this->money($period['expense']),
                'net' => $this->money($period['net']),
                'transactions' => (int) $period['transaction_count'],
            ];
        }

        $totals = $data['totals'] ?? [];

        return [$columns, $rows, [
            $this->summaryMoney('summary.income', $totals['income'] ?? null, $locale),
            $this->summaryMoney('summary.expense', $totals['expense'] ?? null, $locale),
            $this->summaryMoney('summary.net', $totals['net'] ?? null, $locale),
            $this->summaryCount('summary.transactions', $totals['transaction_count'] ?? 0, $locale),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:list<Column>,1:list<array<string,mixed>>,2:list<array{label:string,value:string}>}
     */
    private function trend(array $data, ExportLocale $locale): array
    {
        $columns = [
            ...$this->periodColumns($locale),
            new Column('total', ExportLabels::get('column.total', $locale), Column::MONEY),
            new Column('transactions', ExportLabels::get('column.transactions', $locale), Column::COUNT),
        ];

        $rows = [];

        foreach ($data['periods'] ?? [] as $period) {
            $rows[] = [
                ...$this->periodCells($period, $locale),
                'total' => $this->money($period['total']),
                'transactions' => (int) $period['transaction_count'],
            ];
        }

        $totals = $data['totals'] ?? [];

        return [$columns, $rows, [
            $this->summaryMoney('summary.total', $totals['total'] ?? null, $locale),
            $this->summaryCount('summary.transactions', $totals['transaction_count'] ?? 0, $locale),
            $this->summaryMoney('summary.average_per_period', $totals['average_per_period'] ?? null, $locale),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:list<Column>,1:list<array<string,mixed>>,2:list<array{label:string,value:string}>}
     */
    private function netWorth(array $data, ExportLocale $locale): array
    {
        $columns = [
            ...$this->periodColumns($locale),
            new Column('net_worth', ExportLabels::get('column.net_worth', $locale), Column::MONEY),
        ];

        $rows = [];

        foreach ($data['series'] ?? [] as $point) {
            $rows[] = [
                ...$this->periodCells($point, $locale),
                'net_worth' => $this->money($point['net_worth']),
            ];
        }

        return [$columns, $rows, [
            $this->summaryMoney('summary.total', $data['total'] ?? null, $locale),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:list<Column>,1:list<array<string,mixed>>,2:list<array{label:string,value:string}>}
     */
    private function topCategories(array $data, ExportLocale $locale): array
    {
        $columns = [
            new Column('rank', ExportLabels::get('column.rank', $locale), Column::COUNT),
            new Column('category', ExportLabels::get('column.category', $locale)),
            new Column('path', ExportLabels::get('column.path', $locale)),
            new Column('total', ExportLabels::get('column.total', $locale), Column::MONEY),
            new Column('transactions', ExportLabels::get('column.transactions', $locale), Column::COUNT),
            new Column('share', ExportLabels::get('column.share', $locale), Column::PERCENT),
        ];

        $rows = [];

        foreach ($data['categories'] ?? [] as $category) {
            $rows[] = [
                'rank' => (int) $category['rank'],
                'category' => (string) $category['name'],
                'path' => (string) ($category['path'] ?? ''),
                'total' => $this->money($category['total']),
                'transactions' => (int) $category['transaction_count'],
                'share' => (float) $category['percentage'],
            ];
        }

        $totals = $data['totals'] ?? [];

        return [$columns, $rows, [
            $this->summaryMoney('summary.total', $totals['total'] ?? null, $locale),
            $this->summaryCount('summary.transactions', $totals['transaction_count'] ?? 0, $locale),
            $this->summaryMoney('summary.other', $data['other']['total'] ?? null, $locale),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:list<Column>,1:list<array<string,mixed>>,2:list<array{label:string,value:string}>}
     */
    private function topMerchants(array $data, ExportLocale $locale): array
    {
        $columns = [
            new Column('rank', ExportLabels::get('column.rank', $locale), Column::COUNT),
            new Column('merchant', ExportLabels::get('column.merchant', $locale)),
            new Column('total', ExportLabels::get('column.total', $locale), Column::MONEY),
            new Column('transactions', ExportLabels::get('column.transactions', $locale), Column::COUNT),
            new Column('share', ExportLabels::get('column.share', $locale), Column::PERCENT),
        ];

        $rows = [];

        foreach ($data['merchants'] ?? [] as $merchant) {
            $rows[] = [
                'rank' => (int) $merchant['rank'],
                'merchant' => (string) $merchant['payee'],
                'total' => $this->money($merchant['total']),
                'transactions' => (int) $merchant['transaction_count'],
                'share' => (float) $merchant['percentage'],
            ];
        }

        return [$columns, $rows, [
            $this->summaryMoney('summary.total', $data['totals']['total'] ?? null, $locale),
            $this->summaryMoney('summary.unlabelled', $data['unlabelled']['total'] ?? null, $locale),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:list<Column>,1:list<array<string,mixed>>,2:list<array{label:string,value:string}>}
     */
    private function topAccounts(array $data, ExportLocale $locale): array
    {
        $columns = [
            new Column('rank', ExportLabels::get('column.rank', $locale), Column::COUNT),
            new Column('account', ExportLabels::get('column.account', $locale)),
            new Column('account_type', ExportLabels::get('column.account_type', $locale)),
            new Column('currency', ExportLabels::get('column.currency', $locale)),
            new Column('total', ExportLabels::get('column.total', $locale), Column::MONEY),
            new Column('transactions', ExportLabels::get('column.transactions', $locale), Column::COUNT),
            new Column('share', ExportLabels::get('column.share', $locale), Column::PERCENT),
        ];

        $rows = [];

        foreach ($data['accounts'] ?? [] as $account) {
            $rows[] = [
                'rank' => (int) $account['rank'],
                'account' => (string) ($account['name'] ?? ''),
                'account_type' => (string) ($account['type'] ?? ''),
                'currency' => (string) ($account['currency'] ?? ''),
                'total' => $this->money($account['total']),
                'transactions' => (int) $account['transaction_count'],
                'share' => (float) $account['percentage'],
            ];
        }

        return [$columns, $rows, [
            $this->summaryMoney('summary.total', $data['totals']['total'] ?? null, $locale),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0:list<Column>,1:list<array<string,mixed>>,2:list<array{label:string,value:string}>}
     */
    private function categoryBreakdown(array $data, ExportLocale $locale): array
    {
        $columns = [
            new Column('category', ExportLabels::get('column.category', $locale)),
            new Column('path', ExportLabels::get('column.path', $locale)),
            new Column('total', ExportLabels::get('column.total', $locale), Column::MONEY),
            new Column('transactions', ExportLabels::get('column.transactions', $locale), Column::COUNT),
            new Column('share', ExportLabels::get('column.share', $locale), Column::PERCENT),
        ];

        $rows = [];

        foreach ($data['slices'] ?? [] as $slice) {
            $rows[] = [
                'category' => (string) $slice['name'],
                'path' => (string) ($slice['path'] ?? ''),
                'total' => $this->money($slice['total']),
                'transactions' => (int) $slice['transaction_count'],
                'share' => (float) $slice['percentage'],
            ];
        }

        $totals = $data['totals'] ?? [];

        return [$columns, $rows, [
            $this->summaryMoney('summary.total', $totals['total'] ?? null, $locale),
            $this->summaryCount('summary.transactions', $totals['transaction_count'] ?? 0, $locale),
        ]];
    }

    /** @return list<Column> */
    private function periodColumns(ExportLocale $locale): array
    {
        return [
            new Column('period', ExportLabels::get('column.period', $locale)),
            new Column('start', ExportLabels::get('column.start', $locale), Column::DATE),
            new Column('end', ExportLabels::get('column.end', $locale), Column::DATE),
        ];
    }

    /**
     * @param  array<string, mixed>  $period
     * @return array<string, string>
     */
    private function periodCells(array $period, ExportLocale $locale): array
    {
        return [
            'period' => (string) $period['key'],
            'start' => LocalizedDate::iso((string) $period['start'], $locale),
            'end' => LocalizedDate::iso((string) $period['end'], $locale),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{label:string,value:string}>
     */
    private function meta(array $data, ExportLocale $locale): array
    {
        $meta = $data['meta'] ?? [];
        $rows = [];

        if (isset($meta['from'], $meta['to'])) {
            $rows[] = [
                'label' => ExportLabels::get('meta.range', $locale),
                'value' => LocalizedDate::long((string) $meta['from'], $locale)
                    .' — '.LocalizedDate::long((string) $meta['to'], $locale),
            ];
        }

        if (isset($meta['currency'])) {
            $rows[] = [
                'label' => ExportLabels::get('meta.currency', $locale),
                'value' => (string) $meta['currency'],
            ];
        }

        if (isset($meta['bucket'])) {
            $rows[] = [
                'label' => ExportLabels::get('meta.bucket', $locale),
                'value' => ExportLabels::get('bucket.'.$meta['bucket'], $locale),
            ];
        }

        return $rows;
    }

    /** @param  array{value:int,currency:string}  $view */
    private function money(array $view): Money
    {
        // Rebuilt from minor units, never from the `decimal` string: the
        // integer is the amount and the string is a rendering of it.
        return Money::of((int) $view['value'], (string) $view['currency']);
    }

    /**
     * @param  array{value:int,currency:string}|null  $view
     * @return array{label:string,value:string}
     */
    private function summaryMoney(string $key, ?array $view, ExportLocale $locale): array
    {
        return [
            'label' => ExportLabels::get($key, $locale),
            'value' => $view === null ? '' : $this->money($view)->toDecimalString(),
        ];
    }

    /** @return array{label:string,value:string} */
    private function summaryCount(string $key, int|string $value, ExportLocale $locale): array
    {
        return [
            'label' => ExportLabels::get($key, $locale),
            'value' => (string) (int) $value,
        ];
    }
}
