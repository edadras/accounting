<?php

declare(strict_types=1);

namespace Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Reports\Models\ReportExport;

/**
 * @property ReportExport $resource
 */
final class ReportExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $export = $this->resource;

        return [
            'id' => $export->id,
            'report' => $export->report_type,
            'format' => $export->format,
            'locale' => $export->locale,
            'filters' => $export->filters,
            'status' => $export->status,
            'filename' => $export->filename,
            'size' => $export->size,
            'row_count' => $export->row_count,
            'error' => $export->error,
            'created_at' => $export->created_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),

            // Present only once there is something behind it, so a client can
            // poll on its absence rather than on a status string.
            'download_url' => $export->isReady()
                ? url("/api/v1/reports/exports/{$export->id}/download")
                : null,
        ];
    }
}
