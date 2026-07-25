<?php

declare(strict_types=1);

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Models\AiInsight;

/** @mixin AiInsight */
final class AiInsightResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'body' => $this->body,
            // The figures behind the sentence travel with it: the client shows
            // them on tap, and a claim about someone's money should be checkable.
            'data' => $this->data,
            'score' => (float) $this->score,
            'period' => [
                'from' => $this->period_start?->toDateString(),
                'to' => $this->period_end?->toDateString(),
            ],
            'computed_at' => $this->computed_at?->toIso8601String(),
            'dismissed_at' => $this->dismissed_at?->toIso8601String(),
        ];
    }
}
