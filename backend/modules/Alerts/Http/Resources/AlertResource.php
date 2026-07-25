<?php

declare(strict_types=1);

namespace Modules\Alerts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Alerts\Models\Alert;

/** @mixin Alert */
final class AlertResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'payload' => $this->payload,
            'channels' => $this->deliveries(),
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
        ];
    }
}
