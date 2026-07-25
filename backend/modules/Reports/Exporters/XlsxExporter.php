<?php

declare(strict_types=1);

namespace Modules\Reports\Exporters;

use App\Core\Money\Currency;
use App\Core\Money\Money;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ExportFormat;
use Modules\Reports\Support\ReportTable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * A workbook whose amount columns can actually be summed.
 *
 * The whole point of exporting to a spreadsheet is that the user adds a column
 * up, sorts by it, charts it. So amounts are written as numbers carrying a
 * currency number-format, not as text — a right-aligned string that looks like
 * money but returns 0 from SUM() is the failure this exporter exists to avoid.
 *
 * This is the one place an amount becomes a float, because a spreadsheet cell
 * has no other numeric type. It is converted from the decimal string rather
 * than by dividing the minor units, and the number-format carries the currency
 * and its exact number of decimal places, so nothing is rounded on the way in.
 */
final class XlsxExporter implements ReportExporter
{
    public function format(): ExportFormat
    {
        return ExportFormat::Xlsx;
    }

    public function export(ReportTable $table): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle($table->title));

        // Persian and Arabic workbooks open with column A on the right, which
        // is where a reader of those languages looks first.
        $sheet->setRightToLeft($table->locale->isRtl());

        $this->writeHeader($sheet, $table);
        $this->writeRows($sheet, $table);
        $this->autoSize($sheet, $table);

        // Header row stays visible while scrolling a long report.
        $sheet->freezePane('A2');

        return $this->toString($spreadsheet);
    }

    private function writeHeader(Worksheet $sheet, ReportTable $table): void
    {
        foreach ($table->columns as $index => $column) {
            $sheet->setCellValueExplicit(
                [$index + 1, 1],
                $column->label,
                DataType::TYPE_STRING,
            );
        }

        $sheet->getStyle([1, 1, max(1, count($table->columns)), 1])
            ->getFont()
            ->setBold(true);
    }

    private function writeRows(Worksheet $sheet, ReportTable $table): void
    {
        foreach ($table->rows as $rowIndex => $row) {
            foreach ($table->columns as $columnIndex => $column) {
                $this->writeCell(
                    $sheet,
                    [$columnIndex + 1, $rowIndex + 2],
                    $row[$column->key] ?? null,
                    $column,
                );
            }
        }
    }

    /** @param  array{int, int}  $coordinate */
    private function writeCell(Worksheet $sheet, array $coordinate, mixed $value, Column $column): void
    {
        $cell = $sheet->getCell($coordinate);

        switch ($column->type) {
            case Column::MONEY:
                if (! $value instanceof Money) {
                    return;
                }

                $cell->setValueExplicit((float) $value->toDecimalString(), DataType::TYPE_NUMERIC);
                $cell->getStyle()->getNumberFormat()->setFormatCode($this->moneyFormat($value->currency));
                break;

            case Column::COUNT:
                $cell->setValueExplicit((int) $value, DataType::TYPE_NUMERIC);
                $cell->getStyle()->getNumberFormat()->setFormatCode('#,##0');
                break;

            case Column::PERCENT:
                $cell->setValueExplicit((float) $value, DataType::TYPE_NUMERIC);
                $cell->getStyle()->getNumberFormat()->setFormatCode('0.00');
                break;

            default:
                // Explicitly a string: a category path like "/home/food" or an
                // account named "2026" must not be guessed into a date or a
                // number by the value binder.
                $cell->setValueExplicit((string) ($value ?? ''), DataType::TYPE_STRING);
                $cell->getStyle()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_GENERAL);
        }
    }

    /** '#,##0.00 "₺"' — grouping is fine here; a cell holds a number, not text. */
    private function moneyFormat(Currency $currency): string
    {
        $decimals = $currency->minorUnit > 0
            ? '.'.str_repeat('0', $currency->minorUnit)
            : '';

        return '#,##0'.$decimals.' "'.$currency->symbol.'"';
    }

    private function autoSize(Worksheet $sheet, ReportTable $table): void
    {
        foreach (array_keys($table->columns) as $index) {
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(true);
        }
    }

    /** Sheet names cannot exceed 31 characters or contain []:*?/\ */
    private function sheetTitle(string $title): string
    {
        $clean = trim((string) preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $title));

        return mb_substr($clean === '' ? 'Report' : $clean, 0, 31);
    }

    private function toString(Spreadsheet $spreadsheet): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $bytes = (string) ob_get_clean();

        $spreadsheet->disconnectWorksheets();

        return $bytes;
    }
}
