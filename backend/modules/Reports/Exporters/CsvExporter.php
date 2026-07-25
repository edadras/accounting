<?php

declare(strict_types=1);

namespace Modules\Reports\Exporters;

use App\Core\Money\Money;
use League\Csv\Writer;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ExportFormat;
use Modules\Reports\Support\ReportTable;

/**
 * The plainest possible file: a header row and one row per report row.
 *
 * Amounts are written as bare decimal strings — "350.00", never "350,00" and
 * never "1,350.00". A thousands separator is the character that makes a CSV
 * unreadable in half the world: it is the field separator in most of Europe and
 * Turkey, and even quoted it turns a number into text for whatever parses the
 * file next. Grouping is a display choice and this file is data.
 *
 * A report with no rows still gets its header row, because an empty table is a
 * true answer and a zero-byte file is not one.
 */
final class CsvExporter implements ReportExporter
{
    /** Characters a spreadsheet would treat as the start of a formula. */
    private const FORMULA_PREFIXES = ['=', '+', '@', "\t", "\r"];

    public function format(): ExportFormat
    {
        return ExportFormat::Csv;
    }

    public function export(ReportTable $table): string
    {
        $writer = Writer::createFromString();

        // Excel reads a UTF-8 CSV as the local ANSI codepage unless a BOM says
        // otherwise, which turns every Persian and Turkish name into mojibake.
        $writer->setOutputBOM(Writer::BOM_UTF8);

        $writer->insertOne($table->headers());

        foreach ($table->rows as $row) {
            $writer->insertOne(array_map(
                fn (Column $column): string => $this->cell($row[$column->key] ?? null, $column),
                $table->columns,
            ));
        }

        return $writer->toString();
    }

    private function cell(mixed $value, Column $column): string
    {
        return match ($column->type) {
            Column::MONEY => $value instanceof Money ? $value->toDecimalString() : '',
            Column::COUNT => (string) (int) $value,
            Column::PERCENT => number_format((float) $value, 2, '.', ''),
            default => $this->text((string) ($value ?? '')),
        };
    }

    /**
     * User-supplied text only — a payee or an account name can be anything,
     * including "=WEBSERVICE(...)", which Excel would execute on open. Numeric
     * columns are produced by this code and never need it; escaping them would
     * be worse than the risk, since a negative amount legitimately starts "-".
     */
    private function text(string $value): string
    {
        foreach (self::FORMULA_PREFIXES as $prefix) {
            if (str_starts_with($value, $prefix)) {
                return "'".$value;
            }
        }

        return $value;
    }
}
