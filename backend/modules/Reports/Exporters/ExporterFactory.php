<?php

declare(strict_types=1);

namespace Modules\Reports\Exporters;

use Modules\Reports\Support\ExportFormat;

/**
 * The whitelist that turns a requested format into an exporter.
 *
 * A `match` over a closed enum, exactly like ReportCatalog: the request says
 * "xlsx" and this says which class that is. Nothing composes a class name, a
 * container key or a path out of anything the caller sent.
 */
final class ExporterFactory
{
    public function for(ExportFormat $format): ReportExporter
    {
        return match ($format) {
            ExportFormat::Csv => new CsvExporter,
            ExportFormat::Xlsx => new XlsxExporter,
            ExportFormat::Pdf => new PdfExporter,
        };
    }
}
