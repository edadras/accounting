<?php

declare(strict_types=1);

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiDraft;

/** @mixin AiDraft */
final class AiDraftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source' => $this->source,
            'status' => $this->status,
            'confidence' => (float) $this->confidence,
            // Always true while pending. Restated on the wire so a client
            // cannot read a draft as a completed record by omission.
            'needs_confirmation' => (bool) $this->needs_confirmation,
            'warnings' => $this->warnings ?? [],
            'input_text' => $this->input_text,
            'draft' => $this->payload,
            'transaction_id' => $this->transaction_id,
            'document_id' => $this->document_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
