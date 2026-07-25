<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Models\Document;
use Modules\Documents\Models\Documentable;
use Modules\Documents\Support\AttachableTypes;

/**
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'disk' => $this->disk,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'kind' => $this->kind,
            'checksum' => $this->checksum,

            'ocr' => [
                'status' => $this->ocr_status,
                'text' => $this->ocr_text,
                'data' => $this->ocr_data,
            ],

            'uploaded_by' => $this->uploaded_by,

            'attachments' => $this->whenLoaded(
                'documentables',
                fn () => $this->documentables
                    ->map(fn (Documentable $link) => [
                        'type' => AttachableTypes::aliasFor($link->documentable_type),
                        'id' => $link->documentable_id,
                    ])
                    ->all(),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
