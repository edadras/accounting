<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Reports\Support\Bucket;
use Modules\Reports\Support\ExportFormat;
use Modules\Reports\Support\ExportLocale;
use Modules\Reports\Support\ReportCatalog;
use Modules\Reports\Support\ReportFilters;

/**
 * One endpoint for every report: GET /api/v1/reports/{type}.
 *
 * `{type}` is checked against a fixed list and then dispatched through a
 * `match`. It never reaches a class name, a container binding or a query — the
 * whole point is that no request can name a class or a column.
 *
 * Both the list and the dispatch live in ReportCatalog, shared with the export
 * endpoint: one whitelist, so there is only one thing to keep right.
 */
final class ReportController
{
    public function __construct(private readonly ReportCatalog $catalog) {}

    public function show(Request $request, string $type): JsonResponse
    {
        if (! ReportCatalog::supports($type)) {
            return response()->json([
                'error' => [
                    'code' => 'unknown_report',
                    'message' => 'Unknown report type.',
                    'allowed' => ReportCatalog::TYPES,
                    'request_id' => $request->header('X-Request-Id'),
                ],
            ], 404);
        }

        $input = $request->validate(ReportFilters::rules());

        return response()->json([
            'data' => $this->catalog->run($type, ReportFilters::fromArray($input)),
        ]);
    }

    /** What this endpoint can be asked for — so the client never hardcodes it. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'types' => ReportCatalog::TYPES,
                'buckets' => Bucket::values(),
                'export_formats' => ExportFormat::values(),
                'export_locales' => ExportLocale::values(),
            ],
        ]);
    }
}
