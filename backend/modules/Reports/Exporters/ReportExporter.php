<?php

declare(strict_types=1);

namespace Modules\Reports\Exporters;

use Modules\Reports\Support\ExportFormat;
use Modules\Reports\Support\ReportTable;

/**
 * Turns a flattened report into the bytes of a file.
 *
 * The bytes are returned rather than written, so the same exporter serves a
 * response the caller waits for and a job that stores the file for later.
 * Exports are small enough to hold in memory by construction — anything large
 * enough not to be has already been bounded by the inline row limit.
 */
interface ReportExporter
{
    public function format(): ExportFormat;

    public function export(ReportTable $table): string;
}
