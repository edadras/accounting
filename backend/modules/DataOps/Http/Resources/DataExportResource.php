<?php

declare(strict_types=1);

namespace Modules\DataOps\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\DataOps\Models\DataExport;

/**
 * @mixin DataExport
 */
final class DataExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'format' => $this->format,
            'size' => $this->size,
            'error' => $this->error,
            'requested_by' => $this->requested_by,
            'expires_at' => $this->expires_at?->toIso8601String(),

            // The stored path is never published: it names a disk location the
            // client has no business addressing.
            'download_url' => $this->isDownloadable()
                ? url("/api/v1/exports/{$this->id}/download")
                : null,

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
