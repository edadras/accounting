<?php

declare(strict_types=1);

namespace Modules\Reports\Support;

/**
 * A report, flattened into the one shape every exporter understands.
 *
 * Reports answer different questions and their payloads are shaped differently;
 * a file is always a header row and some rows underneath. Flattening happens
 * once, here, so CSV, XLSX and PDF cannot drift apart in what they contain.
 *
 * Money cells hold `App\Core\Money\Money`, not a formatted string and never a
 * float: the decision about how to write an amount belongs to the format, and
 * an amount that has already been turned into text cannot be un-formatted.
 */
final readonly class ReportTable
{
    /**
     * @param  list<Column>  $columns
     * @param  list<array<string, mixed>>  $rows  keyed by column key
     * @param  list<array{label:string,value:string}>  $summary
     * @param  list<array{label:string,value:string}>  $meta
     */
    public function __construct(
        public string $type,
        public string $title,
        public array $columns,
        public array $rows,
        public array $summary,
        public array $meta,
        public ExportLocale $locale,
        public string $emptyLabel = '',
    ) {}

    /** @return list<string> */
    public function headers(): array
    {
        return array_map(static fn (Column $column): string => $column->label, $this->columns);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
