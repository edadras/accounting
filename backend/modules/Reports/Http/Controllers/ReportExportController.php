<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Modules\Core\Support\WorkspaceContext;
use Modules\Reports\Actions\RunReportExport;
use Modules\Reports\Http\Resources\ReportExportResource;
use Modules\Reports\Jobs\GenerateReportExport;
use Modules\Reports\Models\ReportExport;
use Modules\Reports\Support\ExportFormat;
use Modules\Reports\Support\ExportLocale;
use Modules\Reports\Support\ReportCatalog;
use Modules\Reports\Support\ReportFilters;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/v1/reports/{type}/export, and the two endpoints for collecting one.
 *
 * `{type}` and `format` are both checked against fixed lists before anything
 * happens — the report through ReportCatalog, the same whitelist the JSON
 * endpoint uses, and the format through a closed enum. Neither ever becomes a
 * class name, a path or a container key.
 *
 * A small export comes back in the response; a large one becomes a job and the
 * caller polls `GET reports/exports/{id}` until a download_url appears. Where
 * the line is drawn, and why, is in Config/reports.php.
 */
final class ReportExportController
{
    public function __construct(private readonly ReportCatalog $catalog) {}

    public function store(Request $request, string $type): Response
    {
        if (! ReportCatalog::supports($type)) {
            return $this->unknownReport($request);
        }

        $input = $request->validate([
            ...ReportFilters::rules(),
            'format' => ['required', Rule::in(ExportFormat::values())],
            'locale' => ['nullable', Rule::in(ExportLocale::values())],
            'queued' => ['nullable', 'boolean'],
        ]);

        $filters = ReportFilters::fromArray($input);
        $format = ExportFormat::from($input['format']);
        $locale = ExportLocale::parse($input['locale'] ?? null);

        $export = new ReportExport;
        $export->fill([
            'workspace_id' => app(WorkspaceContext::class)->require()->id,
            'report_type' => $type,
            'format' => $format->value,
            'locale' => $locale->value,
            'filters' => $filters->toArray(),
            'status' => ReportExport::STATUS_PENDING,
            'requested_by' => $request->user()?->id,
        ])->save();

        if ($this->shouldQueue($type, $format, $filters, $request)) {
            GenerateReportExport::dispatch($export->workspace_id, $export->id);

            return (new ReportExportResource($export))->response()->setStatusCode(202);
        }

        $bytes = app(RunReportExport::class)->handle($export);

        return $this->file($bytes, $export->fresh() ?? $export);
    }

    /** Polling: status now, and a download_url once there is one. */
    public function show(string $id): JsonResponse
    {
        $export = ReportExport::query()->findOrFail($id);

        return (new ReportExportResource($export))->response();
    }

    public function download(string $id): Response
    {
        $export = ReportExport::query()->findOrFail($id);

        abort_unless($export->isReady(), 404);

        $disk = Storage::disk((string) $export->disk);

        abort_unless($disk->exists((string) $export->path), 404);

        return $this->file((string) $disk->get((string) $export->path), $export);
    }

    public function index(Request $request): JsonResponse
    {
        $exports = ReportExport::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json([
            'data' => ReportExportResource::collection($exports),
            'meta' => [
                'formats' => ExportFormat::values(),
                'locales' => ExportLocale::values(),
                'types' => ReportCatalog::TYPES,
            ],
        ]);
    }

    /** A PDF of a thousand rows is a seven-second request; a CSV of one is not. */
    private function shouldQueue(
        string $type,
        ExportFormat $format,
        ReportFilters $filters,
        Request $request,
    ): bool {
        if ($request->boolean('queued')) {
            return true;
        }

        $limit = (int) config("reports.exports.inline_row_limit.{$format->value}", 1000);

        return $this->catalog->estimatedRows($type, $filters) > $limit;
    }

    private function file(string $bytes, ReportExport $export): Response
    {
        $filename = $export->filename ?? "{$export->report_type}.{$export->exportFormat()->extension()}";

        return response($bytes, 200, [
            'Content-Type' => $export->exportFormat()->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Content-Length' => (string) strlen($bytes),
            // So a client that streamed an export can still come back for it.
            'X-Report-Export-Id' => $export->id,
        ]);
    }

    private function unknownReport(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'unknown_report',
                'message' => 'Unknown report type.',
                'allowed' => ReportCatalog::TYPES,
                'request_id' => $request->header('X-Request-Id'),
            ],
        ], 404);
    }
}
