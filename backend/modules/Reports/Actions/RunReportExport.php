<?php

declare(strict_types=1);

namespace Modules\Reports\Actions;

use Illuminate\Support\Facades\Storage;
use Modules\Reports\Exporters\ExporterFactory;
use Modules\Reports\Models\ReportExport;
use Modules\Reports\Support\ReportCatalog;
use Modules\Reports\Support\ReportFilters;
use Modules\Reports\Support\ReportTableFactory;
use Throwable;

/**
 * Runs a report, renders it and stores the file.
 *
 * The same action serves both routes an export can take. A small one is built
 * inside the request and the bytes go straight back; a large one is built by a
 * job. Either way the file is stored and the row is updated, so an export that
 * was streamed once can still be downloaded again from its id.
 *
 * The caller must already be inside the export's workspace — the report queries
 * read the active workspace, and this action does not set it.
 */
final class RunReportExport
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly ReportTableFactory $tables,
        private readonly ExporterFactory $exporters,
    ) {}

    /** @return string the bytes that were stored */
    public function handle(ReportExport $export): string
    {
        $export->update(['status' => ReportExport::STATUS_PROCESSING, 'error' => null]);

        try {
            $filters = ReportFilters::fromArray($export->filters ?? []);
            $data = $this->catalog->run($export->report_type, $filters);
            $table = $this->tables->make($export->report_type, $data, $export->exportLocale());

            $exporter = $this->exporters->for($export->exportFormat());
            $bytes = $exporter->export($table);

            $disk = (string) config('reports.exports.disk');
            $extension = $exporter->format()->extension();
            $path = "workspaces/{$export->workspace_id}/report-exports/{$export->id}.{$extension}";

            Storage::disk($disk)->put($path, $bytes);

            $export->update([
                'status' => ReportExport::STATUS_READY,
                'disk' => $disk,
                'path' => $path,
                'filename' => $this->filename($export, $filters, $extension),
                'size' => strlen($bytes),
                'row_count' => count($table->rows),
                'completed_at' => now(),
            ]);

            return $bytes;
        } catch (Throwable $failure) {
            // Recorded before rethrowing: a poller has to be able to see that
            // its export will never arrive, rather than waiting forever.
            $export->update([
                'status' => ReportExport::STATUS_FAILED,
                'error' => mb_substr($failure->getMessage(), 0, 1000),
                'completed_at' => now(),
            ]);

            throw $failure;
        }
    }

    /** What the browser saves it as: the report, the range, the extension. */
    private function filename(ReportExport $export, ReportFilters $filters, string $extension): string
    {
        return sprintf(
            '%s_%s_%s.%s',
            $export->report_type,
            $filters->from,
            $filters->to,
            $extension,
        );
    }
}
