<?php

declare(strict_types=1);

namespace Modules\Reports\Exporters;

use App\Core\Money\Money;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Modules\Reports\Support\ArabicShaper;
use Modules\Reports\Support\Column;
use Modules\Reports\Support\ExportFormat;
use Modules\Reports\Support\ReportTable;

/**
 * A printable report.
 *
 * Two things make this work for `fa` and `ar`, and neither comes from dompdf:
 *
 *  1. The font. Vazirmatn ships in this module (Resources/fonts) and is
 *     registered with the PDF engine directly. It is a copy on purpose — the
 *     Flutter app owns its own assets, and a backend that read a file out of
 *     the mobile app's tree at runtime would break the first time either moved.
 *  2. The text itself, which ArabicShaper turns into joined presentation forms
 *     in visual order before it reaches the HTML. dompdf neither shapes nor
 *     reorders; handed logical-order Persian it draws unjoined letters running
 *     the wrong way.
 *
 * The column order is reversed for a right-to-left locale for the same reason:
 * nothing in the layout engine will do it, so the first column is emitted last
 * and lands on the right of the page where it belongs.
 */
final class PdfExporter implements ReportExporter
{
    private const FONT_FAMILY = 'vazirmatn';

    public function format(): ExportFormat
    {
        return ExportFormat::Pdf;
    }

    public function export(ReportTable $table): string
    {
        $dompdf = new Dompdf($this->options());

        $fonts = __DIR__.'/../Resources/fonts';

        $dompdf->getFontMetrics()->registerFont(
            ['family' => self::FONT_FAMILY, 'weight' => 400, 'style' => 'normal'],
            $fonts.'/Vazirmatn-Regular.ttf',
        );

        $dompdf->getFontMetrics()->registerFont(
            ['family' => self::FONT_FAMILY, 'weight' => 700, 'style' => 'normal'],
            $fonts.'/Vazirmatn-Bold.ttf',
        );

        $dompdf->loadHtml($this->html($table), 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function options(): Options
    {
        $cache = (string) config('reports.exports.font_cache');

        File::ensureDirectoryExists($cache);

        $options = new Options;
        $options->setFontDir($cache);
        $options->setFontCache($cache);

        // The only directory the engine may read from is the module's own font
        // folder, and it may not open a network connection at all: report HTML
        // is built here, but it carries user-supplied names.
        $options->setChroot([realpath(__DIR__.'/../Resources/fonts') ?: __DIR__]);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont(self::FONT_FAMILY);

        return $options;
    }

    private function html(ReportTable $table): string
    {
        $rtl = $table->locale->isRtl();
        $columns = $rtl ? array_reverse($table->columns) : $table->columns;

        $head = '';

        foreach ($columns as $column) {
            $head .= '<th class="'.($column->isNumeric() ? 'num' : 'txt').'">'
                .$this->text($column->label).'</th>';
        }

        $body = '';

        foreach ($table->rows as $row) {
            $body .= '<tr>';

            foreach ($columns as $column) {
                $body .= '<td class="'.($column->isNumeric() ? 'num' : 'txt').'">'
                    .$this->cell($row[$column->key] ?? null, $column).'</td>';
            }

            $body .= '</tr>';
        }

        if ($body === '') {
            $body = '<tr><td class="empty" colspan="'.count($columns).'">'
                .$this->text($table->emptyLabel).'</td></tr>';
        }

        $meta = '';

        foreach ($table->meta as $entry) {
            $meta .= '<div><span class="k">'.$this->text($entry['label']).'</span>'
                .'<span class="v">'.$this->text($entry['value']).'</span></div>';
        }

        $summary = '';

        foreach ($table->summary as $entry) {
            $summary .= '<div><span class="k">'.$this->text($entry['label']).'</span>'
                .'<span class="v">'.$this->text($entry['value']).'</span></div>';
        }

        return '<html><head><meta charset="utf-8"><style>'
            .$this->css($rtl)
            .'</style></head><body>'
            .'<h1>'.$this->text($table->title).'</h1>'
            .'<div class="meta">'.$meta.'</div>'
            .'<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>'
            .'<div class="summary">'.$summary.'</div>'
            .'</body></html>';
    }

    private function css(bool $rtl): string
    {
        $align = $rtl ? 'right' : 'left';
        $away = $rtl ? 'left' : 'right';

        return <<<CSS
            @page { margin: 18mm 14mm; }
            body { font-family: {$this->fontStack()}; font-size: 9pt; color: #111; direction: {$this->direction($rtl)}; }
            h1 { font-size: 15pt; margin: 0 0 6pt; text-align: {$align}; }
            .meta { font-size: 8pt; color: #555; margin-bottom: 8pt; text-align: {$align}; }
            .meta div, .summary div { margin-bottom: 2pt; }
            .meta .k, .summary .k { display: inline-block; min-width: 90pt; color: #777; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border-bottom: 0.5pt solid #ddd; padding: 3pt 4pt; white-space: nowrap; }
            th { background: #f2f2f2; font-weight: bold; text-align: {$align}; }
            td.txt { text-align: {$align}; }
            /* Amounts are Latin digits and read left to right whatever the page
               direction is, so they line up on the side the text runs away to. */
            th.num, td.num { text-align: {$away}; }
            td.empty { text-align: center; color: #888; padding: 12pt; }
            .summary { margin-top: 10pt; font-size: 9pt; text-align: {$align}; }
            .summary .v { font-weight: bold; }
            CSS;
    }

    private function fontStack(): string
    {
        return self::FONT_FAMILY.', sans-serif';
    }

    private function direction(bool $rtl): string
    {
        // Only sets the default alignment in dompdf — the reordering is ours.
        return $rtl ? 'rtl' : 'ltr';
    }

    private function cell(mixed $value, Column $column): string
    {
        return match ($column->type) {
            Column::MONEY => $value instanceof Money ? $this->text($value->toDecimalString()) : '',
            Column::COUNT => $this->text((string) (int) $value),
            Column::PERCENT => $this->text(number_format((float) $value, 2, '.', '')),
            default => $this->text((string) ($value ?? '')),
        };
    }

    /** Shaped first, escaped second: the shaper needs the real characters, and
     *  the entities it would otherwise see are not text to be reordered. */
    private function text(string $value): string
    {
        return htmlspecialchars(ArabicShaper::present($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
