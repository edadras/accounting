<?php

declare(strict_types=1);

namespace Modules\Capture\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Http\Resources\AiDraftResource;
use Modules\Capture\Models\CaptureMessage;

/** @mixin CaptureMessage */
final class CaptureMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $draft = $this->draft;

        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'status' => $this->status,
            'sender' => $this->sender,
            'subject' => $this->subject,
            'received_at' => $this->received_at?->toIso8601String(),
            'matched_pattern' => $this->matched_pattern,
            'reason' => $this->reason,
            'parsed' => $this->parsed,
            'duplicate_of_id' => $this->duplicate_of_id,
            'transaction_id' => $this->transaction_id,
            // Restated on the wire rather than implied: a captured message is a
            // suggestion until someone confirms it, whatever the status says.
            'needs_confirmation' => $draft !== null && $draft->isPending(),
            'draft' => $draft === null ? null : (new AiDraftResource($draft))->toArray($request),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
